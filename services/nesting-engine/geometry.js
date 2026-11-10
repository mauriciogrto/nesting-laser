import ClipperLib from 'clipper-lib';
import clipping from 'polygon-clipping';

const SCALE = 1000;
const EPSILON = 1e-5;

export function signedArea(points) {
  return points.reduce((area, point, index) => {
    const next = points[(index + 1) % points.length];
    return area + point[0] * next[1] - next[0] * point[1];
  }, 0) / 2;
}

export function bounds(points) {
  const xs = points.map(point => point[0]);
  const ys = points.map(point => point[1]);
  return {minX: Math.min(...xs), minY: Math.min(...ys), maxX: Math.max(...xs), maxY: Math.max(...ys)};
}

export function normalizeRing(points) {
  if (!Array.isArray(points) || points.length < 3 || points.length > 2000) throw new Error('Contorno deve ter de 3 a 2000 pontos.');
  const result = points.map(point => {
    if (!Array.isArray(point) || point.length !== 2 || !point.every(number => typeof number === 'number' && Number.isFinite(number))) {
      throw new Error('Coordenada inválida.');
    }
    return [...point];
  });
  if (Math.hypot(result[0][0] - result.at(-1)[0], result[0][1] - result.at(-1)[1]) < EPSILON) result.pop();
  if (result.length < 3 || Math.abs(signedArea(result)) < EPSILON) throw new Error('Contorno degenerado.');
  return result;
}

function closed(ring) { return [...ring, ring[0]]; }
function geometry(rings) { return rings.map(closed); }

export function polygonArea(rings) {
  return Math.abs(signedArea(rings[0])) - rings.slice(1).reduce((sum, ring) => sum + Math.abs(signedArea(ring)), 0);
}

export function areaOfMultiPolygon(multi) {
  return multi.reduce((sum, polygon) => sum + polygon.reduce((area, ring, index) => area + (index ? -1 : 1) * Math.abs(signedArea(ring)), 0), 0);
}

export function normalizePart(input) {
  const raw = input.contornos ?? (input.contorno ? [input.contorno] : null);
  if (!Array.isArray(raw) || !raw.length) throw new Error('Peça sem contorno.');
  const rings = raw.map(normalizeRing);
  rings.sort((a, b) => Math.abs(signedArea(b)) - Math.abs(signedArea(a)));
  for (const hole of rings.slice(1)) {
    if (areaOfMultiPolygon(clipping.difference([closed(hole)], [closed(rings[0])])) > EPSILON) {
      throw new Error('Furo fora do contorno externo.');
    }
  }
  const box = bounds(rings[0]);
  const normalized = rings.map(ring => ring.map(([x, y]) => [x - box.minX, y - box.minY]));
  if (polygonArea(normalized) <= EPSILON) throw new Error('Área da peça deve ser positiva.');
  return {rings: normalized, area: polygonArea(normalized)};
}

export function rotatedPart(part, degrees) {
  const radians = degrees * Math.PI / 180;
  const cosine = Math.cos(radians), sine = Math.sin(radians);
  const rotated = part.rings.map(ring => ring.map(([x, y]) => [x * cosine - y * sine, x * sine + y * cosine]));
  const box = bounds(rotated[0]);
  return rotated.map(ring => ring.map(([x, y]) => [x - box.minX, y - box.minY]));
}

// Contact search uses a bounded number of vertices; final collision checks
// always use the complete original rings.
export function contactOutline(ring, limit = 32) {
  if (ring.length <= limit) return ring;
  const step = Math.ceil(ring.length / limit);
  return ring.filter((_, index) => index % step === 0);
}

export function translated(rings, x, y) { return rings.map(ring => ring.map(point => [point[0] + x, point[1] + y])); }

export function fitsInside(rings, sheet) {
  try { return areaOfMultiPolygon(clipping.difference(geometry(rings), geometry(sheet))) <= EPSILON; }
  catch { return false; }
}

// For an irregular remnant, the bounding rectangle is not its real edge.
// Check clearance against every segment of the actual sheet outline.
export function fitsWithClearance(rings, sheet, clearance = 0) {
  if (!fitsInside(rings, sheet)) return false;
  if (clearance <= 0) return true;
  const limit = Math.max(0, clearance - 0.001) ** 2;
  const part = rings[0], edge = sheet[0];
  for (let i = 0; i < part.length; i++) {
    const a = part[i], b = part[(i + 1) % part.length];
    for (let j = 0; j < edge.length; j++) {
      const c = edge[j], d = edge[(j + 1) % edge.length];
      if (pointSegmentDistanceSquared(a, c, d) < limit ||
          pointSegmentDistanceSquared(b, c, d) < limit ||
          pointSegmentDistanceSquared(c, a, b) < limit ||
          pointSegmentDistanceSquared(d, a, b) < limit) return false;
    }
  }
  return true;
}

export function overlaps(first, second) {
  try { return areaOfMultiPolygon(clipping.intersection(geometry(first), geometry(second))) > EPSILON; }
  catch { return true; }
}

function pointSegmentDistanceSquared(point, start, end) {
  const dx = end[0] - start[0], dy = end[1] - start[1];
  const length = dx * dx + dy * dy;
  const t = length ? Math.max(0, Math.min(1, ((point[0] - start[0]) * dx + (point[1] - start[1]) * dy) / length)) : 0;
  return (point[0] - start[0] - t * dx) ** 2 + (point[1] - start[1] - t * dy) ** 2;
}

// The required clearance applies to actual cut contours, including hole edges,
// rather than the rectangular bounding boxes of irregular parts.
export function tooClose(first, second, clearance = 0) {
  if (overlaps(first, second)) return true;
  if (clearance <= 0) return false;
  const limit = Math.max(0, clearance - 0.001) ** 2;
  for (const ringA of first) for (const ringB of second) {
    for (let i = 0; i < ringA.length; i++) {
      const a = ringA[i], nextA = ringA[(i + 1) % ringA.length];
      for (let j = 0; j < ringB.length; j++) {
        const b = ringB[j], nextB = ringB[(j + 1) % ringB.length];
        if (Math.max(a[0], nextA[0]) + clearance < Math.min(b[0], nextB[0]) ||
            Math.max(b[0], nextB[0]) + clearance < Math.min(a[0], nextA[0]) ||
            Math.max(a[1], nextA[1]) + clearance < Math.min(b[1], nextB[1]) ||
            Math.max(b[1], nextB[1]) + clearance < Math.min(a[1], nextA[1])) continue;
        if (pointSegmentDistanceSquared(a, b, nextB) < limit || pointSegmentDistanceSquared(nextA, b, nextB) < limit ||
            pointSegmentDistanceSquared(b, a, nextA) < limit || pointSegmentDistanceSquared(nextB, a, nextA) < limit) return true;
      }
    }
  }
  return false;
}

function cross(a, b, c) { return (b[0] - a[0]) * (c[1] - a[1]) - (b[1] - a[1]) * (c[0] - a[0]); }

function strictlyInside(point, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const a = ring[i], b = ring[j];
    const length = Math.hypot(b[0] - a[0], b[1] - a[1]);
    if (length > EPSILON && Math.abs(cross(a, b, point)) / length < EPSILON &&
        point[0] >= Math.min(a[0], b[0]) - EPSILON && point[0] <= Math.max(a[0], b[0]) + EPSILON &&
        point[1] >= Math.min(a[1], b[1]) - EPSILON && point[1] <= Math.max(a[1], b[1]) + EPSILON) return false;
    if ((a[1] > point[1]) !== (b[1] > point[1]) &&
        point[0] < (b[0] - a[0]) * (point[1] - a[1]) / (b[1] - a[1]) + a[0]) inside = !inside;
  }
  return inside;
}

// A conservative, fast collision check for automatic candidate search. Holes
// are treated as solid here; the public manual-layout validator remains exact.
export function outerOverlaps(first, second) {
  const a = first[0], b = second[0];
  const boxA = bounds(a), boxB = bounds(b);
  if (boxA.maxX <= boxB.minX + EPSILON || boxB.maxX <= boxA.minX + EPSILON ||
      boxA.maxY <= boxB.minY + EPSILON || boxB.maxY <= boxA.minY + EPSILON) return false;
  for (let i = 0; i < a.length; i++) {
    const a1 = a[i], a2 = a[(i + 1) % a.length];
    for (let j = 0; j < b.length; j++) {
      const b1 = b[j], b2 = b[(j + 1) % b.length];
      if (Math.max(a1[0], a2[0]) <= Math.min(b1[0], b2[0]) + EPSILON ||
          Math.max(b1[0], b2[0]) <= Math.min(a1[0], a2[0]) + EPSILON ||
          Math.max(a1[1], a2[1]) <= Math.min(b1[1], b2[1]) + EPSILON ||
          Math.max(b1[1], b2[1]) <= Math.min(a1[1], a2[1]) + EPSILON) continue;
      const c1 = cross(a1, a2, b1), c2 = cross(a1, a2, b2);
      const c3 = cross(b1, b2, a1), c4 = cross(b1, b2, a2);
      if (c1 * c2 < -EPSILON && c3 * c4 < -EPSILON) return true;
    }
  }
  if (a.some(point => strictlyInside(point, b)) || b.some(point => strictlyInside(point, a))) return true;
  // Coincident contours have every vertex on the other boundary.
  for (let i = 0; i < a.length; i++) {
    const prev = a[(i + a.length - 1) % a.length], next = a[(i + 1) % a.length];
    const sample = [(prev[0] + a[i][0] + next[0]) / 3, (prev[1] + a[i][1] + next[1]) / 3];
    if (strictlyInside(sample, a) && strictlyInside(sample, b)) return true;
  }
  return false;
}

// SVGnest's orbital placement strategy: vertices of the Minkowski A + (-B)
// form no-fit-polygon contact candidates. We then verify every candidate with
// exact polygon clipping, including concave outlines and holes.
export function nfpCandidates(stationary, moving) {
  const a = stationary.map(([x, y]) => ({X: Math.round(x * SCALE), Y: Math.round(y * SCALE)}));
  const b = moving.map(([x, y]) => ({X: Math.round(-x * SCALE), Y: Math.round(-y * SCALE)}));
  try {
    return ClipperLib.Clipper.MinkowskiSum(b, a, true).flatMap(path => {
      const candidates = [];
      for (let i = 0; i < path.length; i++) {
        const current = path[i], next = path[(i + 1) % path.length];
        const x1 = current.X / SCALE, y1 = current.Y / SCALE;
        const x2 = next.X / SCALE, y2 = next.Y / SCALE;
        candidates.push([x1, y1]);
        if (y1 !== y2 && Math.min(y1, y2) <= 0 && Math.max(y1, y2) >= 0) candidates.push([x1 + (x2 - x1) * (-y1) / (y2 - y1), 0]);
        if (x1 !== x2 && Math.min(x1, x2) <= 0 && Math.max(x1, x2) >= 0) candidates.push([0, y1 + (y2 - y1) * (-x1) / (x2 - x1)]);
      }
      return candidates;
    });
  } catch {
    return [];
  }
}

export function validateSheet(chapa) {
  if (!chapa || typeof chapa.largura_mm !== 'number' || typeof chapa.altura_mm !== 'number' ||
      !Number.isFinite(chapa.largura_mm) || !Number.isFinite(chapa.altura_mm) ||
      chapa.largura_mm <= 0 || chapa.altura_mm <= 0) throw new Error('Dimensões de chapa inválidas.');
  const rectangular = !chapa.contorno;
  const outer = rectangular
    ? [[0, 0], [chapa.largura_mm, 0], [chapa.largura_mm, chapa.altura_mm], [0, chapa.altura_mm]]
    : normalizeRing(chapa.contorno);
  const box = bounds(outer);
  if (box.minX < -EPSILON || box.minY < -EPSILON || box.maxX > chapa.largura_mm + EPSILON || box.maxY > chapa.altura_mm + EPSILON) {
    throw new Error('Contorno da chapa fora das dimensões informadas.');
  }
  return {rings: [outer], area: Math.abs(signedArea(outer)), rectangular, width: chapa.largura_mm, height: chapa.altura_mm};
}

export function largestFreeRectangle(sheet, placed) {
  if (!sheet.rectangular || !placed.length) return null;
  const top = Math.max(...placed.map(item => bounds(item.world[0]).maxY));
  const right = Math.max(...placed.map(item => bounds(item.world[0]).maxX));
  const candidates = [
    {x: 0, y: top, width: sheet.width, height: sheet.height - top},
    {x: right, y: 0, width: sheet.width - right, height: sheet.height},
  ].filter(value => value.width > EPSILON && value.height > EPSILON);
  candidates.sort((a, b) => b.width * b.height - a.width * a.height);
  const rect = candidates[0];
  return rect ? [[rect.x, rect.y], [rect.x + rect.width, rect.y], [rect.x + rect.width, rect.y + rect.height], [rect.x, rect.y + rect.height]] : null;
}
