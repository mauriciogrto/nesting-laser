import {bounds, fitsWithClearance, largestFreeRectangle, normalizePart, polygonArea, rotatedPart, tooClose, translated, validateSheet} from './geometry.js';

export function evaluateLayout(payload) {
  const sheet = validateSheet(payload?.chapa);
  const margin = Number(payload?.margem_borda_mm ?? 0);
  const spacing = Number(payload?.distancia_pecas_mm ?? 0);
  if (!Number.isFinite(margin) || !Number.isFinite(spacing) || margin < 0 || spacing < 0 || margin > 100 || spacing > 100) {
    throw new Error('Margem ou distância mínima inválida.');
  }
  const pieces = payload?.pecas_posicionadas;
  if (!Array.isArray(pieces) || !pieces.length || pieces.length > 100) throw new Error('Envie de 1 a 100 peças posicionadas.');
  const placed = [];
  let usedArea = 0;
  for (const item of pieces) {
    if (!Number.isFinite(item?.x) || !Number.isFinite(item?.y) || !Number.isFinite(item?.rotacao_graus)) throw new Error('Posição inválida.');
    const part = normalizePart(item);
    const world = translated(rotatedPart(part, item.rotacao_graus), item.x, item.y);
    const box = bounds(world[0]);
    if (box.minX < margin - 1e-5 || box.minY < margin - 1e-5 ||
        box.maxX > sheet.width - margin + 1e-5 || box.maxY > sheet.height - margin + 1e-5) {
      throw new Error(margin > 0 ? 'Uma peça ultrapassa a margem de segurança da chapa.' : 'Uma peça está fora da chapa.');
    }
    if (!fitsWithClearance(world, sheet.rings, margin)) throw new Error(margin > 0 ? 'Uma peça ultrapassa a margem de segurança da chapa.' : 'Uma peça está fora da chapa.');
    if (placed.some(previous => {
      const old = previous.box;
      if (box.maxX + spacing <= old.minX + 0.001 || old.maxX + spacing <= box.minX + 0.001 ||
          box.maxY + spacing <= old.minY + 0.001 || old.maxY + spacing <= box.minY + 0.001) return false;
      return tooClose(world, previous.world, spacing);
    })) throw new Error('Há peças sobrepostas ou próximas demais. Reposicione antes de exportar.');
    placed.push({world, box});
    usedArea += polygonArea(part.rings);
  }
  return {area_utilizada_pct: Math.round(usedArea / sheet.area * 10000) / 100,
    sobra_contorno: largestFreeRectangle(sheet, placed)};
}
