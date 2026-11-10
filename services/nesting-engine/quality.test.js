import test from 'node:test';
import assert from 'node:assert/strict';
import {nest} from './engine.js';
import {improveNesting, shouldImprove} from './quality.js';

test('angular search positions a rectangular part that fits only diagonally', async () => {
  const payload = {
    chapa: {largura_mm: 55, altura_mm: 55},
    margem_borda_mm: 2, distancia_pecas_mm: 2,
    pecas: [{id: 'longa', quantidade: 1, contornos: [[[0, 0], [60, 0], [60, 10], [0, 10]]]}],
  };
  assert.equal(shouldImprove(payload), true);
  const cardinal = nest(payload, {timeoutMs: 1000, fastOnly: true});
  assert.equal(cardinal.pecas_posicionadas.length, 0);
  const improved = await improveNesting(payload, cardinal, 4000);
  assert.equal(improved.pecas_posicionadas.length, 1);
  assert.notEqual(improved.pecas_posicionadas[0].rotacao_graus % 90, 0);
});

test('angular search finds an intermediate fit when 45 degrees also fails', async () => {
  const payload = {
    chapa: {largura_mm: 62, altura_mm: 44},
    margem_borda_mm: 2, distancia_pecas_mm: 2,
    pecas: [{id: 'longa', quantidade: 1, contornos: [[[0, 0], [60, 0], [60, 10], [0, 10]]]}],
  };
  assert.equal(shouldImprove(payload), true);
  const cardinal = nest(payload, {timeoutMs: 1000, fastOnly: true});
  assert.equal(cardinal.pecas_posicionadas.length, 0);
  const improved = await improveNesting(payload, cardinal, 4000);
  assert.equal(improved.pecas_posicionadas.length, 1);
  const angle = improved.pecas_posicionadas[0].rotacao_graus % 90;
  assert.ok(angle > 10 && angle < 80 && Math.abs(angle - 45) > 2);
});

test('detailed contour search includes simple orthogonal profiles', () => {
  const payload = {
    chapa: {largura_mm: 100, altura_mm: 100},
    margem_borda_mm: 2, distancia_pecas_mm: 2,
    pecas: [{id: 'concava', quantidade: 1,
      contornos: [[[0, 0], [40, 0], [40, 10], [10, 10], [10, 40], [0, 40]]]}],
  };
  assert.equal(shouldImprove(payload), true);
});
