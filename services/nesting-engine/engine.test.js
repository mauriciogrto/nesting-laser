import test from 'node:test';
import assert from 'node:assert/strict';
import {nest} from './engine.js';
import {fitsInside, nfpCandidates, overlaps, rotatedPart, translated, validateSheet} from './geometry.js';
import {shouldImprove} from './quality.js';

const rectangle = (width, height) => [[0, 0], [width, 0], [width, height], [0, height]];
const options = {timeoutMs: 3000, maxGenerations: 4};

test('sends batches with internal cutouts to contour-aware optimization', () => {
  assert.equal(shouldImprove({chapa: {largura_mm: 100, altura_mm: 100}, pecas: [
    {id: 1, quantidade: 1, contornos: [rectangle(90, 90), rectangle(40, 40)]},
    {id: 2, quantidade: 1, contorno: rectangle(15, 15)},
  ]}), true);
});

test('sends a single oblique part to angular search even with a short outline', () => {
  assert.equal(shouldImprove({chapa: {largura_mm: 100, altura_mm: 100}, pecas: [
    {id: 1, quantidade: 1, contorno: [[0, 5], [30, 0], [32, 10], [2, 15]]},
  ]}), true);
});

test('places three rectangles inside a 1000x2000 sheet without collision', () => {
  const result = nest({chapa: {largura_mm: 1000, altura_mm: 2000}, pecas: [{id: 1, contorno: rectangle(200, 100), quantidade: 3}]}, options);
  assert.equal(result.pecas_posicionadas.length, 3);
  assert.deepEqual(result.pecas_nao_posicionadas, []);
  assert.equal(result.area_utilizada_pct, 3);
  const sheet = validateSheet({largura_mm: 1000, altura_mm: 2000});
  const shapes = result.pecas_posicionadas.map(part => translated(rotatedPart({rings: part.contornos}, part.rotacao_graus), part.x, part.y));
  shapes.forEach(shape => assert.ok(fitsInside(shape, sheet.rings)));
  for (let i = 0; i < shapes.length; i++) for (let j = i + 1; j < shapes.length; j++) assert.equal(overlaps(shapes[i], shapes[j]), false);
});

test('fills the left side before advancing right and preserves a right-side remnant', () => {
  const result = nest({chapa: {largura_mm: 100, altura_mm: 50},
    pecas: [{id: 1, contorno: rectangle(20, 15), quantidade: 3}]}, options);
  assert.equal(result.pecas_posicionadas.length, 3);
  assert.ok(result.pecas_posicionadas.every(item => Math.abs(item.x) < 0.001));
  assert.deepEqual(result.sobra_contorno, [[20, 0], [100, 0], [100, 50], [20, 50]]);
});

test('uses polygon geometry for a concave part and an irregular sheet', () => {
  const result = nest({
    chapa: {largura_mm: 100, altura_mm: 100, contorno: [[0, 0], [100, 0], [100, 50], [50, 50], [50, 100], [0, 100]]},
    pecas: [{id: 'L', contorno: [[0, 0], [40, 0], [40, 10], [10, 10], [10, 40], [0, 40]], quantidade: 2}],
  }, options);
  assert.equal(result.pecas_posicionadas.length, 2);
  assert.equal(result.sobra_contorno, null);
});

test('automatically interlocks contours with required cut-path clearance instead of rectangular spacing', () => {
  const outline = [[0, 0], [20, 0], [20, 5], [5, 5], [5, 20], [0, 20]];
  const result = nest({chapa: {largura_mm: 27, altura_mm: 27},
    distancia_pecas_mm: 1, pecas: [{id: 1, contorno: outline, quantidade: 2}]}, options);
  assert.equal(result.pecas_posicionadas.length, 2);
});

test('returns explicitly unplaced pieces when the sheet is too small', () => {
  const result = nest({chapa: {largura_mm: 50, altura_mm: 50}, pecas: [{id: 7, contorno: rectangle(40, 40), quantidade: 2}]}, options);
  assert.equal(result.pecas_posicionadas.length, 1);
  assert.equal(result.pecas_nao_posicionadas.length, 1);
  assert.equal(result.area_utilizada_pct, 64);
});

test('NFP calculation yields contact candidate for adjacent rectangles', () => {
  const candidates = nfpCandidates(rectangle(10, 10), rectangle(10, 10));
  assert.ok(candidates.some(([x, y]) => Math.abs(x - 10) < 0.001 && Math.abs(y) < 0.001));
});

test('rejects degenerate input before optimization', () => {
  assert.throws(() => nest({chapa: {largura_mm: 100, altura_mm: 100}, pecas: [{id: 1, contorno: [[0, 0], [1, 0]], quantidade: 1}]}, options), /Contorno/);
});

test('returns the best partial layout when the time limit is reached', () => {
  const result = nest({
    chapa: {largura_mm: 1000, altura_mm: 1000},
    pecas: [{id: 1, contorno: rectangle(40, 40), quantidade: 30}],
  }, {timeoutMs: 50, maxGenerations: 200});
  assert.equal(result.tempo_limite_atingido, true);
  assert.ok(result.pecas_posicionadas.length > 0);
  assert.equal(result.pecas_posicionadas.length + result.pecas_nao_posicionadas.length, 30);
});

test('places nine detailed DXF-like outlines on a 3000x1200 sheet before timeout', () => {
  const detailedCircle = radius => Array.from({length: 180}, (_, index) => {
    const angle = index * 2 * Math.PI / 180;
    return [radius + radius * Math.cos(angle), radius + radius * Math.sin(angle)];
  });
  const result = nest({
    chapa: {largura_mm: 3000, altura_mm: 1200},
    pecas: [110, 125, 150].map((radius, index) => ({id: index + 1, contorno: detailedCircle(radius), quantidade: 3})),
  }, {timeoutMs: 3000, maxGenerations: 4});
  assert.equal(result.pecas_posicionadas.length, 9);
  assert.deepEqual(result.pecas_nao_posicionadas, []);
});

test('respects safe border and minimum piece spacing', () => {
  const result = nest({chapa: {largura_mm: 100, altura_mm: 70},
    margem_borda_mm: 5, distancia_pecas_mm: 10,
    pecas: [{id: 1, contorno: rectangle(30, 20), quantidade: 2}]}, options);
  assert.equal(result.pecas_posicionadas.length, 2);
  for (const item of result.pecas_posicionadas) {
    assert.ok(item.x >= 5 && item.y >= 5);
  }
  assert.ok(Math.abs(result.pecas_posicionadas[0].x - result.pecas_posicionadas[1].x) >= 40 ||
    Math.abs(result.pecas_posicionadas[0].y - result.pecas_posicionadas[1].y) >= 30);
});
