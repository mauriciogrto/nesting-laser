import {spawn} from 'node:child_process';
import {existsSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {bounds, normalizePart, rotatedPart, translated} from './geometry.js';
import {evaluateLayout} from './layout.js';

const script = fileURLToPath(new URL('./search_nesting.py', import.meta.url));
const localPython = fileURLToPath(new URL('../../../.local/venv/Scripts/python.exe', import.meta.url));

export function shouldImprove(payload) {
  return !payload?.chapa?.contorno &&
    Array.isArray(payload?.pecas) && payload.pecas.reduce((sum, item) => sum + item.quantidade, 0) > 0 &&
    payload.pecas.some(item => {
      const ring = item.contornos?.[0] ?? item.contorno ?? [];
      if ((item.contornos?.length ?? 0) > 1 || ring.length > 4) return true;
      if (ring.length === 4 && payload.chapa) {
        const xs = ring.map(point => Number(point[0]));
        const ys = ring.map(point => Number(point[1]));
        const width = Math.max(...xs) - Math.min(...xs);
        const height = Math.max(...ys) - Math.min(...ys);
        const availableWidth = Number(payload.chapa.largura_mm) - 2 * Number(payload.margem_borda_mm ?? 0);
        const availableHeight = Number(payload.chapa.altura_mm) - 2 * Number(payload.margem_borda_mm ?? 0);
        // Even a four-corner profile can fit only at an oblique angle.
        const fitsCardinal = (width <= availableWidth && height <= availableHeight) ||
          (height <= availableWidth && width <= availableHeight);
        if (!fitsCardinal && width * height <= availableWidth * availableHeight) return true;
      }
      return ring.some((point, index) => {
        const next = ring[(index + 1) % ring.length];
        return Math.abs(next[0] - point[0]) > 0.001 && Math.abs(next[1] - point[1]) > 0.001;
      });
    });
}

function runPython(payload, timeoutMs) {
  return new Promise((resolve, reject) => {
    const python = process.env.NESTING_PYTHON_BIN || (existsSync(localPython) ? localPython : 'python3');
    const child = spawn(python, [script, String(timeoutMs / 1000)], {windowsHide: true});
    const output = [], errors = [];
    let bytes = 0;
    const timer = setTimeout(() => child.kill(), timeoutMs + 5000);
    child.stdout.on('data', chunk => { bytes += chunk.length; if (bytes > 20_000_000) child.kill(); else output.push(chunk); });
    child.stderr.on('data', chunk => errors.push(chunk));
    child.on('error', error => { clearTimeout(timer); reject(error); });
    child.on('close', code => {
      clearTimeout(timer);
      if (code !== 0) return reject(new Error(Buffer.concat(errors).toString('utf8').trim() || 'Busca por contornos indisponível.'));
      try { resolve(JSON.parse(Buffer.concat(output).toString('utf8'))); }
      catch (error) { reject(error); }
    });
    child.stdin.on('error', () => {});
    child.stdin.end(JSON.stringify(payload));
  });
}

function occupiedWidth(positions) {
  return Math.max(0, ...positions.map(item => {
    const shape = normalizePart(item);
    return bounds(translated(rotatedPart(shape, item.rotacao_graus), item.x, item.y)[0]).maxX;
  }));
}

function remnantArea(contour) {
  if (!Array.isArray(contour) || contour.length < 3) return 0;
  return Math.abs(contour.reduce((sum, point, index) => {
    const next = contour[(index + 1) % contour.length];
    return sum + point[0] * next[1] - next[0] * point[1];
  }, 0)) / 2;
}

export async function improveNesting(payload, baseline, timeoutMs = 45000) {
  if (!shouldImprove(payload)) return baseline;
  try {
    const result = await runPython({...payload, baseline}, timeoutMs);
    const positions = result.pecas_posicionadas;
    if (!Array.isArray(positions) || positions.length < baseline.pecas_posicionadas.length) return baseline;
    const expected = payload.pecas.flatMap(item => Array.from({length: item.quantidade}, () => item.id));
    const seen = new Set();
    for (const item of positions) {
      if (!Number.isInteger(item.instancia) || item.instancia < 0 || item.instancia >= expected.length ||
          seen.has(item.instancia) || String(item.id) !== String(expected[item.instancia])) return baseline;
      seen.add(item.instancia);
    }
    const checked = evaluateLayout({chapa: payload.chapa, pecas_posicionadas: positions,
      margem_borda_mm: payload.margem_borda_mm, distancia_pecas_mm: payload.distancia_pecas_mm});
    if (positions.length === baseline.pecas_posicionadas.length) {
      const gain = remnantArea(checked.sobra_contorno) - remnantArea(baseline.sobra_contorno);
      if (gain < -0.01 || (Math.abs(gain) <= 0.01 &&
          occupiedWidth(positions) >= occupiedWidth(baseline.pecas_posicionadas) - 0.001)) return baseline;
    }
    return {...baseline, pecas_posicionadas: positions,
      pecas_nao_posicionadas: expected.map((id, instancia) => ({id, instancia})).filter(item => !seen.has(item.instancia)),
      area_utilizada_pct: checked.area_utilizada_pct, sobra_contorno: checked.sobra_contorno,
      tempo_limite_atingido: false, geracoes_executadas: result.tentativas ?? 0};
  } catch (error) {
    console.warn(`Busca por contornos ignorada: ${error.message}`);
    return baseline;
  }
}
