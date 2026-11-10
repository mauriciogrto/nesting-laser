import {performance} from 'node:perf_hooks';
import {bounds, contactOutline, fitsWithClearance, largestFreeRectangle, nfpCandidates, normalizePart, outerOverlaps, rotatedPart, tooClose, translated, validateSheet} from './geometry.js';

const ANGLES = [0, 90, 180, 270];

function candidatePositions(sheet, moving, placed, margin, spacing, contactCache, useContacts) {
  const candidates = [[margin, margin]];
  for (const s of sheet.rings[0]) for (const p of moving.rings[0]) candidates.push([s[0] - p[0], s[1] - p[1]]);
  for (const item of placed) {
    if (useContacts) {
      const key = `${item.shapeIndex}:${item.rotacao_graus}:${moving.shapeIndex}:${moving.angle}`;
      if (!contactCache.has(key)) contactCache.set(key, nfpCandidates(item.variant.approx, moving.approx));
      for (const [dx, dy] of contactCache.get(key)) {
        for (const shift of [0, 2, 5, 10, 20, 40]) {
          candidates.push([item.x + dx + spacing + shift, item.y + dy]);
          if (spacing) candidates.push([item.x + dx, item.y + dy + spacing + shift]);
        }
      }
    }
    const box = bounds(item.world[0]);
    const clearance = spacing > 0 ? spacing + 0.002 : 0;
    candidates.push([box.maxX + clearance, box.minY], [box.minX, box.maxY + clearance],
      [box.maxX + clearance, margin], [margin, box.maxY + clearance]);
  }
  const seen = new Set();
  const movingBox = bounds(moving.rings[0]);
  return candidates.filter(([x, y]) => {
    if (!Number.isFinite(x) || !Number.isFinite(y) || x + movingBox.minX < margin - 1e-5 ||
        y + movingBox.minY < margin - 1e-5 || x + movingBox.maxX > sheet.width - margin + 1e-5 ||
        y + movingBox.maxY > sheet.height - margin + 1e-5) return false;
    const key = `${Math.round(x * 1000)}:${Math.round(y * 1000)}`;
    if (seen.has(key)) return false;
    seen.add(key);
    return true;
  }).sort((a, b) => a[0] - b[0] || a[1] - b[1]).slice(0, useContacts ? 500 : 1500);
}

function evaluate(gene, parts, sheet, deadline, margin, spacing, variants, contactCache, useContacts = true) {
  const placed = [];
  let usedArea = 0;
  for (const index of gene.order) {
    if (performance.now() >= deadline) break;
    const part = parts[index];
    const preferred = gene.rotations[index];
    let chosen = null;
    for (const angle of [preferred, ...ANGLES.filter(value => value !== preferred)]) {
      const variant = variants.get(`${part.shapeIndex}:${angle}`);
      const rotated = variant.rings;
      for (const [x, y] of candidatePositions(sheet, variant, placed, margin, spacing, contactCache, useContacts)) {
        if (performance.now() >= deadline) break;
        const world = translated(rotated, x, y);
        const box = bounds(world[0]);
        if (box.minX < margin - 1e-5 || box.minY < margin - 1e-5 ||
            box.maxX > sheet.width - margin + 1e-5 || box.maxY > sheet.height - margin + 1e-5) continue;
        if (!sheet.rectangular && !fitsWithClearance(world, sheet.rings, margin)) continue;
        const coarseWorld = useContacts ? [translated([variant.approx], x, y)[0]] : null;
        if (placed.some(previous => {
          const old = bounds(previous.world[0]);
          if (box.maxX + spacing <= old.minX + 0.001 || old.maxX + spacing <= box.minX + 0.001 ||
              box.maxY + spacing <= old.minY + 0.001 || old.maxY + spacing <= box.minY + 0.001) return false;
          if (spacing > 0) return tooClose(world, previous.world, spacing);
          if (useContacts && outerOverlaps(coarseWorld, previous.coarseWorld)) return true;
          return outerOverlaps(world, previous.world);
        })) continue;
        chosen = {index, id: part.id, instancia: part.instancia, x: Math.round(x * 1000) / 1000, y: Math.round(y * 1000) / 1000, rotacao_graus: angle, contornos: part.rings, world, coarseWorld, shapeIndex: part.shapeIndex, variant};
        break;
      }
      if (chosen || performance.now() >= deadline) break;
    }
    if (chosen) { placed.push(chosen); usedArea += part.area; }
  }
  const maxY = placed.length ? Math.max(...placed.map(item => bounds(item.world[0]).maxY)) : sheet.height;
  const maxX = placed.length ? Math.max(...placed.map(item => bounds(item.world[0]).maxX)) : sheet.width;
  // Compact from the left edge so the full-height reusable remnant stays on the right.
  const score = placed.length * 1e12 + usedArea * 1e3 - maxX * 100 - maxY;
  return {placed, usedArea, score};
}

function shuffledGene(parts) {
  const order = parts.map((_, index) => index);
  for (let i = order.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [order[i], order[j]] = [order[j], order[i]];
  }
  return {order, rotations: parts.map(() => ANGLES[Math.floor(Math.random() * ANGLES.length)])};
}

function breed(mother, father) {
  const cut = Math.floor(Math.random() * mother.order.length);
  const kept = mother.order.slice(0, cut);
  const order = [...kept, ...father.order.filter(index => !kept.includes(index))];
  const rotations = mother.rotations.map((angle, index) => Math.random() < 0.5 ? angle : father.rotations[index]);
  if (order.length > 1 && Math.random() < 0.7) {
    const a = Math.floor(Math.random() * order.length), b = Math.floor(Math.random() * order.length);
    [order[a], order[b]] = [order[b], order[a]];
  }
  if (rotations.length && Math.random() < 0.7) rotations[Math.floor(Math.random() * rotations.length)] = ANGLES[Math.floor(Math.random() * ANGLES.length)];
  return {order, rotations};
}

export function nest(payload, options = {}) {
  const sheet = validateSheet(payload?.chapa);
  const margin = Number(payload?.margem_borda_mm ?? 0);
  const spacing = Number(payload?.distancia_pecas_mm ?? 0);
  if (!Number.isFinite(margin) || !Number.isFinite(spacing) || margin < 0 || spacing < 0 || margin > 100 || spacing > 100) {
    throw new Error('Margem ou distância mínima inválida.');
  }
  if (!Array.isArray(payload?.pecas) || !payload.pecas.length || payload.pecas.length > 100) throw new Error('Envie de 1 a 100 tipos de peça.');
  const parts = [];
  let instance = 0;
  for (const [shapeIndex, item] of payload.pecas.entries()) {
    if ((!Number.isInteger(item.id) && typeof item.id !== 'string') || !Number.isInteger(item.quantidade) || item.quantidade < 1 || item.quantidade > 100) throw new Error('ID ou quantidade de peça inválidos.');
    const shape = normalizePart(item);
    for (let i = 0; i < item.quantidade; i++) parts.push({id: item.id, instancia: instance++, shapeIndex, ...shape});
  }
  if (parts.length > 100) throw new Error('O limite por pedido é 100 peças.');
  const timeoutMs = Math.min(60000, Math.max(1, options.timeoutMs ?? 30000));
  const maxGenerations = options.fastOnly ? 0 : Math.min(200, Math.max(1, options.maxGenerations ?? 20));
  const deadline = performance.now() + timeoutMs;
  const variants = new Map();
  for (const part of parts) for (const angle of ANGLES) {
    const key = `${part.shapeIndex}:${angle}`;
    if (variants.has(key)) continue;
    const rings = rotatedPart(part, angle);
    variants.set(key, {shapeIndex: part.shapeIndex, angle, rings, approx: contactOutline(rings[0])});
  }
  const contactCache = new Map();

  const sorted = parts.map((_, index) => index).sort((a, b) => parts[b].area - parts[a].area);
  let population = [{order: sorted, rotations: parts.map(() => 0)}, ...Array.from({length: 11}, () => shuffledGene(parts))];
  // A fast conservative layout protects the result if the more detailed
  // contact search reaches its time limit.
  let best = evaluate(population[0], parts, sheet, deadline, margin, spacing, variants, contactCache, false);
  let generations = 0;
  for (; generations < maxGenerations && performance.now() < deadline; generations++) {
    const scored = [];
    for (const gene of population) {
      if (performance.now() >= deadline) break;
      const result = evaluate(gene, parts, sheet, deadline, margin, spacing, variants, contactCache);
      scored.push({gene, result});
      if (result.score > best.score) best = result;
    }
    if (!scored.length) break;
    scored.sort((a, b) => b.result.score - a.result.score);
    const elite = scored.slice(0, Math.max(2, Math.ceil(scored.length / 3))).map(item => item.gene);
    population = [...elite];
    while (population.length < 12) population.push(breed(elite[Math.floor(Math.random() * elite.length)], elite[Math.floor(Math.random() * elite.length)]));
    if (best.placed.length === parts.length && generations >= 3) break;
  }
  const positioned = best.placed.map(({world, coarseWorld, index, shapeIndex, variant, ...item}) => item);
  const found = new Set(positioned.map(item => item.instancia));
  return {
    pecas_posicionadas: positioned,
    pecas_nao_posicionadas: parts.filter(part => !found.has(part.instancia)).map(({id, instancia}) => ({id, instancia})),
    area_utilizada_pct: Math.round((best.usedArea / sheet.area) * 10000) / 100,
    sobra_contorno: largestFreeRectangle(sheet, best.placed),
    tempo_limite_atingido: performance.now() >= deadline,
    geracoes_executadas: generations,
  };
}
