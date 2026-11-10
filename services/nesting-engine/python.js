import {spawn} from 'node:child_process';
import {existsSync, realpathSync} from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

const applicationRoot = fileURLToPath(new URL('../../', import.meta.url));
const scriptRoot = path.join(applicationRoot, 'services', 'dxf-parser');
const privateRoot = path.join(applicationRoot, 'storage', 'app', 'private');
const allowedScripts = new Set([
  'extrair_geometria.py', 'aplicar_offset.py', 'gerar_cnc_saida.py',
  'gerar_dxf_saida.py', 'gerar_pdf_arranjo.py',
]);

function privatePath(value, existing = false) {
  if (typeof value !== 'string' || !path.isAbsolute(value)) return false;
  const target = path.resolve(value);
  if (!target.startsWith(privateRoot + path.sep)) return false;
  if (existing) {
    return existsSync(target) && realpathSync(target).startsWith(realpathSync(privateRoot) + path.sep);
  }
  let parent = path.dirname(target);
  while (!existsSync(parent) && parent !== privateRoot) parent = path.dirname(parent);
  const realParent = realpathSync(parent);
  const realPrivate = realpathSync(privateRoot);
  return realParent === realPrivate || realParent.startsWith(realPrivate + path.sep);
}

export function validatePythonRequest(body) {
  if (!body || !allowedScripts.has(body.script) || !Array.isArray(body.arguments)
      || body.arguments.some(arg => typeof arg !== 'string')
      || typeof body.input_base64 !== 'string') {
    throw new Error('Operação Python inválida.');
  }
  const args = body.arguments;
  if (body.script === 'extrair_geometria.py' && (args.length !== 1 || !privatePath(args[0], true))) {
    throw new Error('Arquivo DXF fora do armazenamento privado.');
  }
  if (body.script === 'gerar_cnc_saida.py' && (args.length !== 1 || (args[0] !== '--preview' && !privatePath(args[0])))) {
    throw new Error('Destino CNC inválido.');
  }
  if (body.script === 'gerar_dxf_saida.py' && (args.length !== 1 || !privatePath(args[0]))) {
    throw new Error('Destino DXF inválido.');
  }
  if (['aplicar_offset.py', 'gerar_pdf_arranjo.py'].includes(body.script) && args.length !== 0) {
    throw new Error('Argumentos Python inválidos.');
  }
  return {script: path.join(scriptRoot, body.script), args, input: Buffer.from(body.input_base64, 'base64')};
}

export function runPython(body) {
  const request = validatePythonRequest(body);
  return new Promise((resolve, reject) => {
    const home = privateRoot;
    const child = spawn(process.env.NESTING_PYTHON_BIN || process.env.DXF_PYTHON_BIN || 'python3',
      [request.script, ...request.args], {
        cwd: applicationRoot,
        env: {...process.env, HOME: home, USERPROFILE: home,
          XDG_CONFIG_HOME: path.join(home, 'python-config'), XDG_CACHE_HOME: path.join(home, 'python-cache')},
        windowsHide: true,
      });
    const stdout = [];
    const stderr = [];
    let size = 0;
    let timedOut = false;
    const timer = setTimeout(() => { timedOut = true; child.kill(); }, Number(process.env.DXF_PARSER_TIMEOUT || 120) * 1000);
    const collect = (chunks, chunk) => {
      size += chunk.length;
      if (size > 32 * 1024 * 1024) child.kill();
      else chunks.push(chunk);
    };
    child.stdout.on('data', chunk => collect(stdout, chunk));
    child.stderr.on('data', chunk => collect(stderr, chunk));
    child.on('error', error => { clearTimeout(timer); reject(error); });
    child.on('close', code => {
      clearTimeout(timer);
      resolve({ok: code === 0 && !timedOut && size <= 32 * 1024 * 1024,
        output_base64: Buffer.concat(stdout).toString('base64'),
        stderr: timedOut ? 'Tempo limite do processamento DXF excedido.' : Buffer.concat(stderr).toString('utf8').slice(0, 4000)});
    });
    child.stdin.on('error', () => {});
    child.stdin.end(request.input);
  });
}
