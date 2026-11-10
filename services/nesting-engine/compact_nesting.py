"""Feasible-region nesting, with large-first construction and partial repacking.

No-fit polygons propose translations against *all* current neighbours at once.
Their union introduces the contact intersections missed by a vertex-only search.
Full compensated polygons, including holes, validate every accepted placement.
"""
from __future__ import annotations

from dataclasses import dataclass
import math
import random
import time

import pyclipper
from shapely.affinity import rotate, translate
from shapely.geometry import Polygon

SCALE = 1000
ANGLES = (0, 90, 180, 270)
RESERVE = 0.025
SIMPLIFICATION = 0.15
HOLE_SIMPLIFICATION = 0.025


@dataclass
class Placement:
    shape: int
    id: object
    instance: int
    angle: int
    x: float
    y: float
    polygon: Polygon
    width: float
    height: float


def path(points):
    return [(round(x * SCALE), round(y * SCALE)) for x, y in points]


def area_score(placed, width, height):
    right = max((p.x + p.width for p in placed), default=0)
    top = max((p.y + p.height for p in placed), default=0)
    return max(width * (height - top), height * (width - right))


class RegionPacker:
    def __init__(self, payload, deadline):
        self.width = float(payload['chapa']['largura_mm'])
        self.height = float(payload['chapa']['altura_mm'])
        self.margin = float(payload.get('margem_borda_mm') or 0)
        self.gap = float(payload.get('distancia_pecas_mm') or 0)
        if (payload['chapa'].get('contorno') or
                not all(math.isfinite(v) for v in (self.width, self.height, self.margin, self.gap)) or
                min(self.width, self.height) <= 0 or min(self.margin, self.gap) < 0):
            raise ValueError('Chapa retangular ou parametros invalidos')
        self.deadline = deadline
        self.obstacles = {}
        self.shapes = []
        self.angle_values = []
        self.parts = []
        for index, item in enumerate(payload['pecas']):
            rings = sorted(item.get('contornos') or [item['contorno']],
                           key=lambda ring: abs(Polygon(ring).area), reverse=True)
            base = Polygon(rings[0], rings[1:])
            if not base.is_valid or base.area <= 0:
                raise ValueError('Contorno de peca invalido')
            left, bottom, _, _ = base.bounds
            base = translate(base, -left, -bottom)
            normalized = [list(map(list, base.exterior.coords[:-1]))]
            normalized.extend(list(map(list, h.coords[:-1])) for h in base.interiors)
            variants = []
            for angle in ANGLES:
                polygon = rotate(base, angle, origin=(0, 0))
                left, bottom, _, _ = polygon.bounds
                polygon = translate(polygon, -left, -bottom)
                outline = Polygon(polygon.exterior).simplify(SIMPLIFICATION, preserve_topology=True)
                variants.append((polygon, path(outline.exterior.coords[:-1]),
                                 polygon.bounds[2], polygon.bounds[3]))
            self.shapes.append((normalized, base.area, variants))
            self.angle_values.append(list(ANGLES))
            for _ in range(int(item['quantidade'])):
                self.parts.append((index, item['id'], len(self.parts)))
        if not self.parts or len(self.parts) > 100:
            raise ValueError('Quantidade de pecas invalida')

    def angle_index(self, shape, degrees):
        """Generate an orientation lazily, retaining fractional degrees in output."""
        if not math.isfinite(degrees):
            raise ValueError('Rotacao invalida')
        degrees = round(degrees % 360, 6) % 360
        values = self.angle_values[shape]
        if degrees not in values:
            base = self.shapes[shape][2][0][0]
            polygon = rotate(base, degrees, origin=(0, 0))
            left, bottom, _, _ = polygon.bounds
            polygon = translate(polygon, -left, -bottom)
            outline = Polygon(polygon.exterior).simplify(SIMPLIFICATION, preserve_topology=True)
            self.shapes[shape][2].append((polygon, path(outline.exterior.coords[:-1]),
                                         polygon.bounds[2], polygon.bounds[3]))
            values.append(degrees)
        return values.index(degrees)

    def obstacle(self, fixed_shape, fixed_angle, moving_shape, moving_angle, fine=False):
        key = (fixed_shape, fixed_angle, moving_shape, moving_angle, fine)
        if key in self.obstacles:
            return self.obstacles[key]
        a = self.shapes[fixed_shape][2][fixed_angle][1]
        b = [(-x, -y) for x, y in self.shapes[moving_shape][2][moving_angle][1]]
        tolerance = HOLE_SIMPLIFICATION if fine else SIMPLIFICATION
        if fine:
            a = path(Polygon(self.shapes[fixed_shape][2][fixed_angle][0].exterior)
                     .simplify(tolerance, preserve_topology=True).exterior.coords[:-1])
            b = [(-x, -y) for x, y in path(Polygon(self.shapes[moving_shape][2][moving_angle][0].exterior)
                     .simplify(tolerance, preserve_topology=True).exterior.coords[:-1])]
        sums = pyclipper.MinkowskiSum(b, a, True)
        if not sums:
            return []
        # Boundary Minkowski sweeps contain an artificial inner ring when the
        # moving part is wholly inside the fixed exterior. Fill that interior;
        # real cutout placements are searched separately below.
        outer = max(sums, key=lambda ring: abs(pyclipper.Area(ring)))
        if not pyclipper.Orientation(outer):
            outer.reverse()
        offset = pyclipper.PyclipperOffset(2, 0.03 * SCALE)
        offset.AddPath(outer, pyclipper.JT_ROUND, pyclipper.ET_CLOSEDPOLYGON)
        result = offset.Execute((self.gap + 2*tolerance + RESERVE) * SCALE)
        if len(self.obstacles) >= 4096:
            self.obstacles.pop(next(iter(self.obstacles)))
        self.obstacles[key] = result
        return result

    def valid(self, polygon, x, y, width, height, placed):
        if (x < self.margin - 1e-7 or y < self.margin - 1e-7 or
                x + width > self.width - self.margin + 1e-7 or
                y + height > self.height - self.margin + 1e-7):
            return False
        for old in placed:
            if (x + width + self.gap <= old.x or old.x + old.width + self.gap <= x or
                    y + height + self.gap <= old.y or old.y + old.height + self.gap <= y):
                continue
            if polygon.intersection(old.polygon).area > 1e-7 or polygon.distance(old.polygon) < self.gap + 0.005:
                return False
        return True

    def candidates(self, part, angle, placed, max_top=None, only_holes=False):
        shape = part[0]
        polygon, _, width, height = self.shapes[shape][2][angle]
        right = self.width - self.margin - width
        top = min(self.height - self.margin, max_top or self.height) - height
        if right < self.margin or top < self.margin:
            return []
        obstacles = []
        for old in ([] if only_holes else placed):
            dx, dy = round(old.x * SCALE), round(old.y * SCALE)
            obstacles.append([[(x + dx, y + dy) for x, y in ring]
                              for ring in self.obstacle(old.shape, old.angle, shape, angle)])

        def free_vertices(rect, skip=None):
            left, bottom, max_x, max_y = rect
            if max_x < left or max_y < bottom:
                return []
            # Keep a finite region even for a nearly exact fit.
            if max_x - left < .002 or max_y - bottom < .002:
                return [(left, bottom), (max_x, max_y)]
            clip = pyclipper.Pyclipper()
            clip.AddPath(path([(left, bottom), (max_x, bottom), (max_x, max_y), (left, max_y)]),
                         pyclipper.PT_SUBJECT, True)
            for index, rings in enumerate(obstacles):
                if index != skip and rings:
                    clip.AddPaths(rings, pyclipper.PT_CLIP, True)
            free = clip.Execute(pyclipper.CT_DIFFERENCE, pyclipper.PFT_NONZERO, pyclipper.PFT_NONZERO)
            return [(x / SCALE, y / SCALE) for ring in free for x, y in ring]

        candidates = [] if only_holes else [(x, y, False) for x, y in free_vertices((self.margin, self.margin, right, top))]
        clearance = self.gap + RESERVE
        for index, old in enumerate(placed):
            for hole in old.polygon.interiors:
                hx0, hy0, hx1, hy1 = hole.bounds
                if hx1-hx0 < width+2*clearance or hy1-hy0 < height+2*clearance:
                    continue
                # The inner rings of the boundary Minkowski sweep are the
                # actual feasible translation regions inside this cutout.
                # A bounding-box corner fails for rounded/reentrant holes.
                hole_outline = Polygon(hole).simplify(HOLE_SIMPLIFICATION, preserve_topology=True)
                moving = [(-x, -y) for x, y in path(Polygon(polygon.exterior)
                    .simplify(HOLE_SIMPLIFICATION, preserve_topology=True).exterior.coords[:-1])]
                sweep = pyclipper.MinkowskiSum(moving, path(hole_outline.exterior.coords[:-1]), True)
                interiors = [list(reversed(r)) for r in sweep if not pyclipper.Orientation(r)]
                if not interiors:
                    continue
                offset = pyclipper.PyclipperOffset(2, .03*SCALE)
                offset.AddPaths(interiors, pyclipper.JT_ROUND, pyclipper.ET_CLOSEDPOLYGON)
                regions = offset.Execute(-(self.gap+2*HOLE_SIMPLIFICATION+RESERVE)*SCALE)
                if not regions:
                    continue
                clip = pyclipper.Pyclipper()
                clip.AddPaths(regions, pyclipper.PT_SUBJECT, True)
                for other, neighbour in enumerate(placed):
                    if (other == index or neighbour.x > hx1+self.gap or neighbour.y > hy1+self.gap or
                            neighbour.x+neighbour.width < hx0-self.gap or neighbour.y+neighbour.height < hy0-self.gap):
                        continue
                    dx, dy = round(neighbour.x*SCALE), round(neighbour.y*SCALE)
                    rings = [[(x+dx, y+dy) for x,y in ring] for ring in
                             self.obstacle(neighbour.shape, neighbour.angle, shape, angle, fine=True)]
                    if rings:
                        clip.AddPaths(rings, pyclipper.PT_CLIP, True)
                free = clip.Execute(pyclipper.CT_DIFFERENCE, pyclipper.PFT_NONZERO, pyclipper.PFT_NONZERO)
                candidates.extend((x/SCALE, y/SCALE, True) for ring in free for x, y in ring)
        return candidates

    def place(self, part, placed, prefer_top=True, max_top=None, tie=0, only_holes=False, angles=None):
        best = None
        current_right = max((p.x + p.width for p in placed), default=0)
        current_top = max((p.y + p.height for p in placed), default=0)
        for angle in (range(4) if angles is None else angles):
            if time.monotonic() >= self.deadline:
                break
            polygon, _, width, height = self.shapes[part[0]][2][angle]
            points = self.candidates(part, angle, placed, max_top, only_holes)
            points.sort(key=lambda p: (not p[2], p[1], p[0]) if prefer_top else (not p[2], p[0], p[1]))
            for x, y, in_hole in points:
                if time.monotonic() >= self.deadline:
                    break
                world = translate(polygon, x, y)
                if not self.valid(world, x, y, width, height, placed):
                    continue
                score = ((not in_hole, max(current_top, y + height), y, x, (angle - tie) % 4)
                         if prefer_top else (not in_hole, max(current_right, x + width), x, y, (angle - tie) % 4))
                value = Placement(*part, angle, x, y, world, width, height)
                if best is None or score < best[0]:
                    best = score, value
                break
        return best[1] if best else None

    def fill_cutouts(self, placed):
        result = list(placed)
        for old in sorted(placed, key=lambda p: p.polygon.area, reverse=True):
            if time.monotonic() >= self.deadline:
                break
            others = [p for p in result if p.instance != old.instance]
            # Moving a host alone would leave its fillers behind. Leave hosts
            # and already nested pieces to the group search.
            if (any(Polygon(h).contains(p.polygon) for h in old.polygon.interiors for p in others) or
                    any(Polygon(h).contains(old.polygon) for p in others for h in p.polygon.interiors)):
                continue
            item = self.place((old.shape,old.id,old.instance), others, only_holes=True)
            if item is not None and area_score(others+[item],self.width,self.height) >= area_score(result,self.width,self.height)-.001:
                result = others+[item]
        return result

    def pack(self, order, fixed=(), prefer_top=True, max_top=None, tie=0):
        placed = list(fixed)
        for part in order:
            if time.monotonic() >= self.deadline:
                break
            item = self.place(part, placed, prefer_top, max_top, tie)
            if item is not None:
                placed.append(item)
        return placed

    def import_layout(self, positions):
        by_instance = {part[2]: part for part in self.parts}
        placed = []
        for item in positions:
            part = by_instance.pop(item['instancia'], None)
            if part is None or str(part[1]) != str(item['id']) or not math.isfinite(item['rotacao_graus']):
                return []
            angle = self.angle_index(part[0], item['rotacao_graus'])
            polygon, _, width, height = self.shapes[part[0]][2][angle]
            x, y = item['x'], item['y']
            if not math.isfinite(x) or not math.isfinite(y):
                return []
            world = translate(polygon, x, y)
            # The baseline may use the engine's smaller numerical reserve.
            if any(world.intersection(old.polygon).area > 1e-5 or
                   world.distance(old.polygon) < self.gap - .002 for old in placed):
                return []
            if x < self.margin - .002 or y < self.margin - .002 or x + width > self.width - self.margin + .002 or y + height > self.height - self.margin + .002:
                return []
            placed.append(Placement(*part, angle, x, y, world, width, height))
        return placed

    def export(self, placed):
        return [{'id': p.id, 'instancia': p.instance, 'x': round(p.x, 3), 'y': round(p.y, 3),
                 'rotacao_graus': self.angle_values[p.shape][p.angle], 'contornos': self.shapes[p.shape][0]} for p in placed]


def solve(payload, seconds=40, baseline=None):
    deadline = time.monotonic() + max(1, min(float(seconds), 300))
    packer = RegionPacker(payload, deadline)
    rng = random.Random(int(payload.get('quality_seed', 42)))
    parts = packer.parts
    area = lambda part: packer.shapes[part[0]][1]
    large_first = sorted(parts, key=area, reverse=True)
    best = packer.import_layout((baseline or {}).get('pecas_posicionadas', []))
    best = packer.fill_cutouts(best)
    attempts = 0
    best_top = True
    while time.monotonic() < deadline:
        if attempts == 0:
            order, prefer_top = large_first, True
            candidate = packer.pack(order, prefer_top=prefer_top)
        elif attempts == 1 and len(best) == len(parts):
            # Evacuate small fillers from external gaps and offer them to the
            # large cutouts first, opening room for the subsequent group search.
            threshold = max(map(area, parts)) * .2
            order = sorted((p for p in parts if area(p) <= threshold), key=area, reverse=True)
            removed = {p[2] for p in order}
            candidate = packer.pack(order, [p for p in best if p.instance not in removed], best_top)
        elif attempts % 3 != 0 and len(best) == len(parts):
            # Remove a local group around an upper/right protrusion, then insert
            # large shapes first and use the remaining small pieces as fillers.
            prefer_top = best_top
            ranked = sorted(best, key=lambda p: p.y + p.height if prefer_top else p.x + p.width, reverse=True)
            anchor = rng.choice(ranked[:min(6, len(ranked))])
            neighbours = sorted(best, key=lambda p: p.polygon.distance(anchor.polygon))
            count = rng.randint(2, min(8, len(parts))) if len(parts) > 1 else 1
            removed = {p.instance for p in neighbours[:count]}
            removed.add(anchor.instance)
            if attempts % 5 == 0:
                removed.update(p.instance for p in ranked[:3])
            fixed = [p for p in best if p.instance not in removed]
            order = sorted((p for p in parts if p[2] in removed), key=lambda p: area(p) * rng.uniform(.75, 1.25), reverse=True)
            candidate = packer.pack(order, fixed, prefer_top, tie=rng.randrange(4))
        else:
            prefer_top = attempts % 9 != 0
            # Preserve a large-first bias, but perturb groups enough to escape
            # the early large-part arrangement chosen by the greedy pass.
            order = sorted(parts, key=lambda p: area(p) * rng.uniform(.45, 1.7), reverse=True)
            candidate = packer.pack(order, prefer_top=prefer_top, tie=rng.randrange(4))
        score = area_score(candidate, packer.width, packer.height)
        old_score = area_score(best, packer.width, packer.height)
        # Equal-envelope moves are useful stepping stones: reduce total extent
        # so another group can subsequently occupy the newly opened pocket.
        extent = lambda items: sum(p.y + p.height if best_top else p.x + p.width for p in items)
        better = score > old_score + .001 or (abs(score - old_score) <= .001 and extent(candidate) < extent(best) - .001)
        if len(candidate) > len(best) or (len(candidate) == len(best) and better):
            best = candidate
            top = packer.width * (packer.height - max(p.y + p.height for p in best))
            right = packer.height * (packer.width - max(p.x + p.width for p in best))
            best_top = top >= right
        attempts += 1
    return {'pecas_posicionadas': packer.export(best), 'tentativas': attempts}


if __name__ == '__main__':
    import json
    import sys
    payload = json.load(sys.stdin)
    print(json.dumps(solve(payload, float(sys.argv[1]) if len(sys.argv) > 1 else 40,
                           payload.get('baseline')), ensure_ascii=True))
