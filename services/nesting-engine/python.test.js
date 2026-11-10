import test from 'node:test';
import assert from 'node:assert/strict';
import {once} from 'node:events';
import {validatePythonRequest} from './python.js';

test('Python bridge permits only known operations and private paths', () => {
  assert.throws(() => validatePythonRequest({script: 'other.py', arguments: [], input_base64: ''}), /inválida/);
  assert.throws(() => validatePythonRequest({script: 'gerar_dxf_saida.py', arguments: ['/tmp/output.dxf'], input_base64: ''}), /Destino/);
  assert.throws(() => validatePythonRequest({script: 'gerar_cnc_saida.py', arguments: ['--preview', 'extra'], input_base64: ''}), /Destino/);
  assert.match(validatePythonRequest({script: 'gerar_cnc_saida.py', arguments: ['--preview'], input_base64: ''}).script, /gerar_cnc_saida\.py$/);
});

test('Python bridge requires the local service token', async () => {
  process.env.NODE_ENV = 'test';
  process.env.NESTING_ENGINE_TOKEN = 'local-test-token';
  const {default: app} = await import('./server.js');
  const server = app.listen(0, '127.0.0.1');
  try {
    await once(server, 'listening');
    const address = server.address();
    const response = await fetch(`http://127.0.0.1:${address.port}/python`, {
      method: 'POST', headers: {'content-type': 'application/json'},
      body: JSON.stringify({script: 'aplicar_offset.py', arguments: [], input_base64: ''}),
    });
    assert.equal(response.status, 401);
  } finally {
    server.close();
    delete process.env.NESTING_ENGINE_TOKEN;
  }
});
