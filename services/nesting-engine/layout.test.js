import test from 'node:test';
import assert from 'node:assert/strict';
import {evaluateLayout} from './layout.js';

const square = [[0, 0], [10, 0], [10, 10], [0, 10]];
const sheet = {largura_mm: 30, altura_mm: 20};
const piece = (x, y) => ({x, y, rotacao_graus: 0, contornos: [square]});

test('fractional rotations use real contours for clearance validation', () => {
  const rotated = {...piece(3, 3), rotacao_graus: 23.75};
  const request = {chapa: {largura_mm: 60, altura_mm: 40}, margem_borda_mm: 3,
    distancia_pecas_mm: 5, pecas_posicionadas: [rotated, {...piece(25, 3), rotacao_graus: 112.8}]};
  assert.doesNotThrow(() => evaluateLayout(request));
  request.pecas_posicionadas[1].x = 12;
  assert.throws(() => evaluateLayout(request), /sobrepostas|próximas/);
});

test('manual layout validates positions and recalculates area and remnant', () => {
  const result = evaluateLayout({chapa: sheet, pecas_posicionadas: [piece(0, 0), piece(10, 0)]});
  assert.equal(result.area_utilizada_pct, 33.33);
  assert.deepEqual(result.sobra_contorno, [[0, 10], [30, 10], [30, 20], [0, 20]]);
  assert.throws(() => evaluateLayout({chapa: sheet, pecas_posicionadas: [piece(0, 0), piece(5, 0)]}), /sobrepostas/);
  assert.throws(() => evaluateLayout({chapa: sheet, pecas_posicionadas: [piece(25, 0)]}), /fora da chapa/);
});

test('manual layout rejects placements inside border margin or piece clearance', () => {
  assert.throws(() => evaluateLayout({chapa: {largura_mm: 50, altura_mm: 30},
    margem_borda_mm: 5, pecas_posicionadas: [piece(0, 5)]}), /margem/);
  assert.throws(() => evaluateLayout({chapa: {largura_mm: 50, altura_mm: 30},
    distancia_pecas_mm: 5, pecas_posicionadas: [piece(0, 0), piece(12, 0)]}), /próximas/);
  assert.doesNotThrow(() => evaluateLayout({chapa: {largura_mm: 50, altura_mm: 30},
    margem_borda_mm: 5, distancia_pecas_mm: 5, pecas_posicionadas: [piece(5, 5), piece(20, 5)]}));
});

test('safe area follows the real edge of an irregular sheet', () => {
  const irregular = {largura_mm: 50, altura_mm: 50,
    contorno: [[0, 0], [50, 0], [50, 20], [20, 20], [20, 50], [0, 50]]};
  assert.throws(() => evaluateLayout({chapa: irregular, margem_borda_mm: 5,
    pecas_posicionadas: [piece(8, 25)]}), /margem/);
  assert.doesNotThrow(() => evaluateLayout({chapa: irregular, margem_borda_mm: 5,
    pecas_posicionadas: [piece(5, 25)]}));
});

test('cut-width clearance follows concave contours rather than their bounding boxes', () => {
  const lShape = [[0, 0], [20, 0], [20, 5], [5, 5], [5, 20], [0, 20]];
  const pieceAt = (x, y, rotacao_graus) => ({x, y, rotacao_graus, contornos: [lShape]});
  const payload = {chapa: {largura_mm: 45, altura_mm: 45}, distancia_pecas_mm: 1,
    pecas_posicionadas: [pieceAt(0, 0, 0), pieceAt(6, 6, 180)]};
  assert.doesNotThrow(() => evaluateLayout(payload));
  payload.pecas_posicionadas[1].rotacao_graus = 0;
  payload.pecas_posicionadas[1].x = 4;
  payload.pecas_posicionadas[1].y = 4;
  assert.throws(() => evaluateLayout(payload), /sobrepostas|próximas/);
});

test('accepts a piece inside an internal cutout only with the required gap', () => {
  const frame = {x: 5, y: 5, rotacao_graus: 0, contornos: [
    [[0, 0], [90, 0], [90, 90], [0, 90]],
    [[25, 25], [65, 25], [65, 65], [25, 65]],
  ]};
  const nested = {x: 35, y: 35, rotacao_graus: 0,
    contornos: [[[0, 0], [15, 0], [15, 15], [0, 15]]]};
  const payload = {chapa: {largura_mm: 100, altura_mm: 100},
    margem_borda_mm: 5, distancia_pecas_mm: 5, pecas_posicionadas: [frame, nested]};
  assert.doesNotThrow(() => evaluateLayout(payload));
  nested.x = 33;
  assert.throws(() => evaluateLayout(payload), /próximas/);
});
