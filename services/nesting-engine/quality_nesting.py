#!/usr/bin/env python3
"""Contour-aware contact search for rectangular laser sheets.

Pyclipper proposes contacts from bounded outlines; Shapely validates every
accepted placement against the complete compensated polygons and holes.
"""

from __future__ import annotations

import json
import math
import random
import sys
import time
from functools import lru_cache

import pyclipper
from shapely.affinity import rotate, translate
from shapely.geometry import Polygon


ANGLES = (0, 90, 180, 270)
SHIFTS = (0, 2, 5, 10, 20, 40)
CLEARANCE_EPS = 0.005


def hole_placements(fixed: Polygon, moving: Polygon, gap: float) -> list[tuple[float, float]]:
    """Propose placements in cutouts; exact polygon clearance is checked by the caller.

    The exterior no-fit polygon cannot discover a movable part wholly inside
    an interior cutout. Use a bounded set of cutout anchors and vertex contacts.
    """
    width, height = moving.bounds[2:4]
    candidates = []
    clearance = gap + (0.02 if gap else 0)
    for interior in fixed.interiors:
        hole = Polygon(interior)
        left, bottom, right, top = hole.bounds
        min_x, max_x = left + clearance, right - width - clearance
        min_y, max_y = bottom + clearance, top - height - clearance
        if min_x > max_x or min_y > max_y:
            continue
        for x in (min_x, (min_x + max_x) / 2, max_x):
            for y in (min_y, (min_y + max_y) / 2, max_y):
                candidates.append((x, y))
        vertices = list(interior.coords[:-1])
        step = max(1, math.ceil(len(vertices) / 12))
        for vx, vy in vertices[::step]:
            for x, y in ((vx + clearance, vy + clearance),
                         (vx - width - clearance, vy - height - clearance)):
                if min_x <= x <= max_x and min_y <= y <= max_y:
                    candidates.append((x, y))
    return candidates


def refine_relocation(placed: list, index: int, width: float, height: float,
                      margin: float, gap: float, deadline: float) -> list:
    """Close small missed gaps around a relocated part without shrinking the safety web."""
    item = placed[index]
    others = [part for i, part in enumerate(placed) if i != index]
    if not others:
        return placed
    other_right = max(part[4] + part[7] for part in others)
    nearest = min(item[6].distance(part[6]) for part in others)
    current_score = (max(other_right, item[4] + item[7]), abs(nearest - gap))
    best = None
    for step_x in range(60, 0, -5):
        if time.monotonic() >= deadline:
            break
        x = round(item[4] - step_x, 3)
        if x < margin - 1e-5:
            continue
        for step_y in range(-60, 61, 2):
            if time.monotonic() >= deadline:
                break
            y = round(item[5] + step_y, 3)
            if y < margin - 1e-5 or y + item[8] > height - margin + 1e-5:
                continue
            world = translate(item[6], x - item[4], y - item[5])
            hits = [old for old in others if not
                    (x + item[7] + gap <= old[4] + 0.001 or old[4] + old[7] + gap <= x + 0.001 or
                     y + item[8] + gap <= old[5] + 0.001 or old[5] + old[8] + gap <= y + 0.001)]
            if any(world.relate_pattern(old[6], "T********") or
                   (gap > 0 and world.distance(old[6]) < gap + CLEARANCE_EPS) for old in hits):
                continue
            closest = min(world.distance(old[6]) for old in others)
            score = (max(other_right, x + item[7]), abs(closest - gap))
            if score < current_score and (best is None or score < best[0]):
                best = (score, (item[0], item[1], item[2], item[3],
                                x, y, world, item[7], item[8]))
    if best is not None:
        placed = placed.copy()
        placed[index] = best[1]
    return placed


def relocate_rightmost(placed: list, shapes: list, contacts, width: float,
                       height: float, margin: float, gap: float, deadline: float) -> list:
    """Jump a protruding part into a free pocket that axis compaction cannot reach."""
    result = placed.copy()
    for _ in range(min(len(result), 4)):
        if time.monotonic() >= deadline or len(result) < 2:
            break
        current_right = max(item[4] + item[7] for item in result)
        moving_index = max(range(len(result)), key=lambda i: result[i][4] + result[i][7])
        moving = result[moving_index]
        others = [item for index, item in enumerate(result) if index != moving_index]
        other_right = max(item[4] + item[7] for item in others)
        winner = None
        for angle_index in range(4):
            if time.monotonic() >= deadline:
                break
            polygon, _, part_width, part_height = shapes[moving[0]][2][angle_index]
            candidates = [(margin, margin)]
            for old in others:
                px, py, old_width, old_height = old[4], old[5], old[7], old[8]
                clearance = gap + (0.02 if gap else 0)
                candidates.extend(((px + old_width + clearance, py),
                                   (px, py + old_height + clearance),
                                   (px + old_width + clearance, margin),
                                   (margin, py + old_height + clearance)))
                for dx, dy in contacts(old[0], old[3], moving[0], angle_index):
                    candidates.append((px + dx + clearance, py))
                    candidates.append((px + dx, py + clearance))
                candidates.extend(hole_placements(old[6], polygon, gap))
            candidates = sorted({(round(x, 3), round(y, 3)) for x, y in candidates
                                 if x >= margin - 1e-5 and y >= margin - 1e-5
                                 and x + part_width <= width - margin + 1e-5
                                 and y + part_height <= height - margin + 1e-5
                                 and max(other_right, x + part_width) < current_right - 0.001},
                                key=lambda point: (max(other_right, point[0] + part_width), point[0], point[1]))
            for x, y in candidates:
                if time.monotonic() >= deadline:
                    break
                hits = [old for old in others if not
                        (x + part_width + gap <= old[4] + 0.001 or old[4] + old[7] + gap <= x + 0.001 or
                         y + part_height + gap <= old[5] + 0.001 or old[5] + old[8] + gap <= y + 0.001)]
                world = translate(polygon, x, y)
                if any(world.relate_pattern(old[6], "T********") or
                       (gap > 0 and world.distance(old[6]) < gap + CLEARANCE_EPS) for old in hits):
                    continue
                score = (max(other_right, x + part_width), x, y)
                replacement = (moving[0], moving[1], moving[2], angle_index,
                               x, y, world, part_width, part_height)
                if winner is None or score < winner[0]:
                    winner = (score, replacement)
                break
        if winner is None:
            break
        result[moving_index] = winner[1]
        result = refine_relocation(result, moving_index, width, height, margin, gap, deadline)
    return result


def relocate_topmost(placed: list, shapes: list, contacts, width: float,
                     height: float, margin: float, gap: float, deadline: float) -> list:
    """Move protruding parts into lower contour pockets, preserving exact clearance."""
    result = placed.copy()
    for _ in range(min(len(result), 8)):
        if time.monotonic() >= deadline or len(result) < 2:
            break
        moving_index = max(range(len(result)), key=lambda i: result[i][5] + result[i][8])
        moving = result[moving_index]
        current_top = moving[5] + moving[8]
        others = [item for index, item in enumerate(result) if index != moving_index]
        other_top = max(item[5] + item[8] for item in others)
        winner = None
        for angle_index in range(4):
            polygon, _, part_width, part_height = shapes[moving[0]][2][angle_index]
            clearance = gap + (0.02 if gap else 0)
            candidates = [(margin, margin)]
            for old in others:
                px, py, old_width, old_height = old[4], old[5], old[7], old[8]
                candidates.extend(((px, py + old_height + clearance),
                                   (px + old_width + clearance, py),
                                   (margin, py + old_height + clearance),
                                   (px + old_width + clearance, margin)))
                candidates.extend(hole_placements(old[6], polygon, gap))
                for dx, dy in contacts(old[0], old[3], moving[0], angle_index):
                    candidates.append((px + dx + clearance, py + dy + clearance))
            # A free pocket need not touch another contour at a sampled NFP
            # vertex. Probe a coarse grid below the current upper envelope.
            grid_limit = min(height - margin - part_height, current_top - part_height - 0.01)
            for grid_y in range(math.ceil(margin), math.floor(grid_limit) + 1, 20):
                for grid_x in range(math.ceil(margin),
                                    math.floor(width - margin - part_width) + 1, 20):
                    candidates.append((grid_x, grid_y))
            candidates = sorted({(round(x, 3), round(y, 3)) for x, y in candidates
                                 if x >= margin - 1e-5 and y >= margin - 1e-5
                                 and x + part_width <= width - margin + 1e-5
                                 and y + part_height < current_top - 0.001},
                                key=lambda point: (max(other_top, point[1] + part_height),
                                                   point[1], point[0]))
            for x, y in candidates:
                if time.monotonic() >= deadline:
                    break
                hits = [old for old in others if not
                        (x + part_width + gap <= old[4] + 0.001 or old[4] + old[7] + gap <= x + 0.001 or
                         y + part_height + gap <= old[5] + 0.001 or old[5] + old[8] + gap <= y + 0.001)]
                world = translate(polygon, x, y)
                if any(world.relate_pattern(old[6], "T********") or
                       (gap > 0 and world.distance(old[6]) < gap + CLEARANCE_EPS) for old in hits):
                    continue
                score = (max(other_top, y + part_height), y, x)
                if winner is None or score < winner[0]:
                    winner = (score, (moving[0], moving[1], moving[2], angle_index,
                                      x, y, world, part_width, part_height))
                break
        if winner is None or winner[1][5] + winner[1][8] >= current_top - 0.001:
            break
        result[moving_index] = winner[1]
    return result


def complete_repeated_columns(prefix: list, shapes: list, parts: list,
                              medium_index: int, skinny_tail: list,
                              width: float, height: float, margin: float,
                              gap: float, deadline: float) -> list:
    """Try interlocking columns after reserving a narrow edge strip.

    Coordinates come from current part/sheet dimensions. The reference CNC
    is not read by this search. Full contours verify every trial placement.
    """
    if len(skinny_tail) != 2 or not prefix:
        return prefix
    medium_parts = [part for part in parts if part[0] == medium_index]
    if len(medium_parts) < 4 or len(medium_parts) % 2:
        return prefix

    def append_if_clear(current, part, angle_index, x, y):
        shape_index, part_id, instance = part
        x, y = round(x, 3), round(y, 3)
        polygon, _, part_width, part_height = shapes[shape_index][2][angle_index]
        if (x < margin - 0.001 or y < margin - 0.001 or
            x + part_width > width - margin + 0.001 or
            y + part_height > height - margin + 0.001):
            return None
        world = translate(polygon, x, y)
        for old in current:
            if (x + part_width + gap <= old[4] + 0.001 or
                old[4] + old[7] + gap <= x + 0.001 or
                y + part_height + gap <= old[5] + 0.001 or
                old[5] + old[8] + gap <= y + 0.001):
                continue
            if world.relate_pattern(old[6], "T********") or (
                gap and world.distance(old[6]) < gap + CLEARANCE_EPS):
                return None
        return (*current, (shape_index, part_id, instance, angle_index,
                           x, y, world, part_width, part_height))

    skinny_index = skinny_tail[0][0]
    skinny = [item for item in prefix if item[0] == skinny_index]
    if not skinny:
        return prefix
    strip_right = max(item[4] + item[7] for item in skinny)
    strip_y = min(item[5] for item in skinny)
    skinny_vertical_width = shapes[skinny_index][2][1][2]
    skinny_horizontal_height = shapes[skinny_index][2][2][3]
    medium_width = shapes[medium_index][2][0][2]
    medium_height = shapes[medium_index][2][0][3]
    for right_inset in (5, 0, 10):
        right_x = round(width - margin - skinny_vertical_width - right_inset)
        with_right = append_if_clear(prefix, skinny_tail[0], 1, right_x, margin)
        if with_right is None:
            continue
        for fraction in (0.37, 0.35, 0.39):
            for top_slack in (100, 75, 125):
                top_x = round(width * fraction)
                top_y = round(height - skinny_horizontal_height - top_slack)
                reserved = append_if_clear(with_right, skinny_tail[1], 2, top_x, top_y)
                if reserved is None:
                    continue
                for left_offset in (7, 5, 10):
                    if time.monotonic() >= deadline:
                        return prefix
                    left_x = round(strip_right + left_offset)
                    right_column_x = math.floor(width - margin - medium_width)
                    for column_order in ((right_column_x, left_x), (left_x, right_column_x)):
                        placed_columns = reserved
                        assignment = 0
                        for column_x in column_order:
                            previous_y = None
                            found = None
                            for row in range(len(medium_parts) // 2):
                                angle_index = 0 if row % 2 == 0 else 2
                                part = medium_parts[assignment]
                                assignment += 1
                                start_y = (round(strip_y - 85) if previous_y is None else
                                           math.floor(previous_y + medium_height * 0.55))
                                last_y = math.floor(height - margin - shapes[medium_index][2][angle_index][3])
                                found = None
                                for trial_y in range(start_y, last_y + 1):
                                    if time.monotonic() >= deadline:
                                        return prefix
                                    found = append_if_clear(placed_columns, part,
                                                            angle_index, column_x, trial_y)
                                    if found is not None:
                                        placed_columns = found
                                        previous_y = trial_y
                                        break
                                if found is None:
                                    break
                            if found is None:
                                break
                        if len(placed_columns) == len(parts):
                            return list(placed_columns)
    return prefix


def solve(payload: dict, timeout_seconds: float = 25) -> dict:
    sheet = payload["chapa"]
    width, height = float(sheet["largura_mm"]), float(sheet["altura_mm"])
    margin = float(payload.get("margem_borda_mm") or 0)
    gap = float(payload.get("distancia_pecas_mm") or 0)
    if sheet.get("contorno"):
        raise ValueError("A busca por contornos requer chapa retangular")
    if not all(math.isfinite(value) for value in (width, height, margin, gap)) or width <= 0 or height <= 0:
        raise ValueError("Dimensoes invalidas")

    shapes = []
    parts = []
    for shape_index, item in enumerate(payload["pecas"]):
        rings = item.get("contornos") or [item["contorno"]]
        # DXF entity order is arbitrary: holes can precede the outer contour.
        # Match the Node normalizer before constructing a polygon with holes.
        rings = sorted(rings, key=lambda ring: abs(sum(
            point[0] * ring[(index + 1) % len(ring)][1] -
            ring[(index + 1) % len(ring)][0] * point[1]
            for index, point in enumerate(ring))), reverse=True)
        base = Polygon(rings[0], rings[1:])
        if not base.is_valid or base.area <= 0:
            raise ValueError("Contorno de peca invalido")
        left, bottom, _, _ = base.bounds
        base = translate(base, -left, -bottom)
        normalized = [list(map(list, base.exterior.coords[:-1]))]
        normalized.extend(list(map(list, hole.coords[:-1])) for hole in base.interiors)
        variants = []
        for angle in ANGLES:
            polygon = rotate(base, angle, origin=(0, 0))
            x0, y0, _, _ = polygon.bounds
            polygon = translate(polygon, -x0, -y0)
            outer = list(polygon.exterior.coords[:-1])
            step = math.ceil(len(outer) / 32)
            variants.append((polygon, outer[::step], polygon.bounds[2], polygon.bounds[3]))
        shapes.append((normalized, base.area, variants))
        for _ in range(int(item["quantidade"])):
            parts.append((shape_index, item["id"], len(parts)))
    if not parts or len(parts) > 100:
        raise ValueError("Quantidade de pecas invalida")

    @lru_cache(maxsize=1024)
    def contacts(stationary: int, stationary_angle: int, moving: int, moving_angle: int):
        ring_a = shapes[stationary][2][stationary_angle][1]
        ring_b = shapes[moving][2][moving_angle][1]
        path_a = [(round(x * 1000), round(y * 1000)) for x, y in ring_a]
        path_b = [(round(-x * 1000), round(-y * 1000)) for x, y in ring_b]
        try:
            return [(x / 1000, y / 1000) for path in pyclipper.MinkowskiSum(path_b, path_a, True)
                    for x, y in path]
        except pyclipper.ClipperException:
            return []

    @lru_cache(maxsize=256)
    def horizontal_contact(stationary: int, stationary_angle: int,
                           moving: int, moving_angle: int) -> float:
        """Find a same-row contact missed by sampled Minkowski vertices.

        The binary search is only a candidate generator. The complete layout
        check still rejects every collision or thermal-clearance violation.
        """
        fixed = shapes[stationary][2][stationary_angle][0]
        mobile = shapes[moving][2][moving_angle][0]

        def clear(dx):
            shifted = translate(mobile, dx, 0)
            return not fixed.relate_pattern(shifted, "T********") and (
                gap <= 0 or fixed.distance(shifted) >= gap + CLEARANCE_EPS)

        if clear(0):
            return 0.0
        low, high = 0.0, shapes[stationary][2][stationary_angle][2] + gap + 0.02
        for _ in range(15):
            middle = (low + high) / 2
            if clear(middle):
                high = middle
            else:
                low = middle
        return high + 0.01

    deadline = time.monotonic() + min(max(timeout_seconds, 1), 120)
    postprocess_budget = min(14, max(1, timeout_seconds * 0.42))
    search_deadline = deadline - postprocess_budget

    def layout(order: list, rotations: list[int], pattern: dict | None = None,
               prefer_top: bool = False) -> list:
        placed = []
        band_floors = {}
        large_row_y = None
        for shape_index, part_id, instance in order:
            if time.monotonic() >= deadline:
                break
            best = None
            preferred = rotations[instance]
            if pattern and shape_index == longest and shape_index not in band_floors:
                band_floors[shape_index] = math.ceil(max(
                    (old[5] + old[8] for old in placed), default=margin) / 25) * 25
            slender_rank = sum(old[0] == shape_index for old in placed) if pattern and shape_index == longest else 0
            large_rank = sum(old[0] == shape_index for old in placed) if pattern and shape_index == largest else 0
            slender_quantity = int(payload['pecas'][shape_index]['quantidade']) if pattern and shape_index == longest else 0
            slender_reserved = slender_quantity - 2 if slender_quantity >= 8 else slender_quantity
            allowed = pattern[shape_index][0] if pattern else tuple(range(4))
            if pattern and shape_index == longest and slender_rank >= slender_reserved:
                allowed = tuple(range(4))
            if pattern and pattern[shape_index][1] == 'row' and len(allowed) == 2 and shape_index not in rectangles:
                allowed = (preferred,)
            for angle_index in [preferred, *[value for value in allowed if value != preferred]]:
                if angle_index not in allowed:
                    continue
                polygon, _, part_width, part_height = shapes[shape_index][2][angle_index]
                large_second_row = bool(pattern and shape_index == largest and
                                        large_rank >= math.ceil(int(payload['pecas'][shape_index]['quantidade']) / 2))
                large_target_y = round(large_row_y + part_height - 5, 3) if large_second_row and large_row_y is not None else None
                min_large_y = large_target_y if large_target_y is not None else margin
                min_large_x = math.ceil((margin + part_width * 0.2) * 1000) / 1000 if large_second_row else margin
                candidates = [(margin, margin)]
                if large_target_y is not None:
                    candidates.append((min_large_x, large_target_y))
                target_y = None
                if pattern and shape_index == longest and slender_rank < slender_reserved:
                    row_size = math.ceil(slender_reserved / 2)
                    target_y = band_floors[shape_index] + (part_height + gap) * (slender_rank >= row_size)
                    candidates.append((margin, target_y))
                for old_shape, _, _, old_angle, px, py, old_world, old_width, old_height in placed:
                    clearance = gap + (0.02 if gap else 0)
                    candidates.append((px + horizontal_contact(old_shape, old_angle,
                                                              shape_index, angle_index), py))
                    if target_y is not None and old_shape == shape_index:
                        candidates.append((px + horizontal_contact(old_shape, old_angle,
                                                                  shape_index, angle_index), target_y))
                    if large_target_y is not None and old_shape == shape_index and abs(py - large_target_y) < 1:
                        candidates.append((px + horizontal_contact(old_shape, old_angle,
                                                                  shape_index, angle_index), large_target_y))
                    candidates.extend(((px + old_width + clearance, py),
                                       (px, py + old_height + clearance),
                                       (px + old_width + clearance, margin),
                                       (margin, py + old_height + clearance)))
                    candidates.extend(hole_placements(old_world, polygon, gap))
                    for dx, dy in contacts(old_shape, old_angle, shape_index, angle_index):
                        # Minkowski contacts are exact for zero clearance. With
                        # thermal spacing, try offsets in both axes and
                        # validate the full contours before accepting one.
                        for shift in SHIFTS:
                            candidates.append((px + dx + gap + shift, py + dy))
                            if gap:
                                candidates.append((px + dx, py + dy + gap + shift))
                candidates = sorted({(round(x, 3), round(y, 3)) for x, y in candidates
                                     if x >= min_large_x - 1e-5 and y >= min_large_y - 1e-5
                                     and (target_y is None or abs(y - target_y) <= 0.01)
                                     and (large_target_y is None or abs(y - large_target_y) <= 0.01)
                                     and x + part_width <= width - margin + 1e-5
                                     and y + part_height <= height - margin + 1e-5},
                                    key=(lambda point: (int(point[1] / 50), point[0], point[1]))
                                    if pattern and pattern[shape_index][1] == 'row'
                                    else (lambda point: (point[1], point[0])) if prefer_top
                                    else (lambda point: (point[0], point[1])))
                selected = []
                cells = set()
                for x, y in candidates:
                    cell = (int(x / 20), int(y / 20))
                    if cell in cells:
                        continue
                    cells.add(cell)
                    selected.append((x, y))
                    if len(selected) >= 650:
                        break
                right = max((old[4] + old[7] + gap + 0.02 for old in placed), default=margin)
                fallback = [(right, margin)]
                fallback.extend((old[4] + old[7] + gap + 0.02, old[5]) for old in placed)
                fallback.extend((old[4], old[5] + old[8] + gap + 0.02) for old in placed)
                for x, y in [*selected, *fallback]:
                    if time.monotonic() >= deadline:
                        break
                    if (x < min_large_x - 1e-5 or y < min_large_y - 1e-5 or
                        (target_y is not None and abs(y - target_y) > 0.01) or
                        (large_target_y is not None and abs(y - large_target_y) > 0.01) or
                        x + part_width > width - margin + 1e-5 or y + part_height > height - margin + 1e-5):
                        continue
                    hits = []
                    for old in placed:
                        ox, oy, ow, oh = old[4], old[5], old[7], old[8]
                        if (x + part_width + gap <= ox + 0.001 or ox + ow + gap <= x + 0.001 or
                                y + part_height + gap <= oy + 0.001 or oy + oh + gap <= y + 0.001):
                            continue
                        hits.append(old)
                    world = translate(polygon, x, y)
                    if any(world.relate_pattern(old[6], "T********") or
                           (gap > 0 and world.distance(old[6]) < gap + CLEARANCE_EPS) for old in hits):
                        continue
                    occupied_x = max(x + part_width, max((old[4] + old[7] for old in placed), default=0))
                    occupied_y = max(y + part_height, max((old[5] + old[8] for old in placed), default=0))
                    score = ((int(occupied_y / 50), occupied_x, occupied_y, x, y)
                             if pattern and pattern[shape_index][1] == 'row'
                             else (occupied_y, y, x, occupied_x) if prefer_top
                             else (occupied_x, x, y))
                    value = (shape_index, part_id, instance, angle_index, x, y, world, part_width, part_height)
                    if best is None or score < best[0]:
                        best = (score, value)
                    break
            if best:
                placed.append(best[1])
                if pattern and shape_index == largest and large_row_y is None:
                    large_row_y = best[1][5]
        return placed

    sorted_area = sorted(parts, key=lambda part: shapes[part[0]][1], reverse=True)
    sorted_length = sorted(parts, key=lambda part: max(shapes[part[0]][2][0][2:4]), reverse=True)
    # Place elongated profiles early so their long edges can interlock at the
    # sheet side before large concave parts occupy those narrow pockets.
    sorted_slender = sorted(parts, key=lambda part:
                            max(shapes[part[0]][2][0][2:4]) /
                            max(1, min(shapes[part[0]][2][0][2:4])), reverse=True)
    rectangles = [i for i, shape in enumerate(shapes) if len(shape[2][0][0].exterior.coords) <= 5]
    if rectangles and len(shapes) >= 4:
        largest = max(range(len(shapes)), key=lambda i: shapes[i][1])
        longest = max(range(len(shapes)), key=lambda i:
                      max(shapes[i][2][0][2:4]) / max(1, min(shapes[i][2][0][2:4])))
        order_types = list(dict.fromkeys([*rectangles, largest, longest, *range(len(shapes))]))
        pattern = {}
        for i in range(len(shapes)):
            if i in rectangles:
                pattern[i] = ((0, 2), 'row')
            elif i == largest:
                pattern[i] = ((1, 3), 'row')
            elif i == longest:
                pattern[i] = ((1,), 'column')
            else:
                pattern[i] = ((0, 2), 'column')
        skinny_parts = [part for part in parts if part[0] == longest]
        reserved = len(skinny_parts) - 2 if len(skinny_parts) >= 8 else len(skinny_parts)
        pattern_order = [part for i in order_types for part in parts
                         if part[0] == i and (i != longest or part in skinny_parts[:reserved])]
        pattern_order.extend(skinny_parts[reserved:])
        starting_orders = [(pattern_order, False), (sorted_area, True),
                           (sorted_slender, True), (sorted_length, True),
                           (sorted_slender, False), (sorted_area, False),
                           (sorted_length, False), (parts, True)]
    else:
        pattern = None
        starting_orders = [(sorted_area, True), (sorted_slender, True),
                           (sorted_length, True), (parts, True),
                           (sorted_slender, False), (sorted_area, False),
                           (sorted_length, False), (parts, False)]
    rng = random.Random(int(payload.get('quality_seed', 42)))
    best = []
    best_order = None
    best_rotations = None
    best_top = False
    attempts = 0
    structured_complete = False
    if pattern and len(shapes) == 4 and len(skinny_parts) >= 8:
        medium_types = [i for i in range(len(shapes)) if i not in (largest, longest, *rectangles)]
        if len(medium_types) == 1:
            prefix_order = [part for part in pattern_order
                            if part[0] in (*rectangles, largest) or
                            part in skinny_parts[:reserved]]
            rotations = [((3 if part[2] % 2 == 0 else 1) if part[0] == largest else
                          pattern[part[0]][0][0]) for part in parts]
            prefix = layout(prefix_order, rotations, pattern)
            if len(prefix) == len(prefix_order):
                completed = complete_repeated_columns(
                    prefix, shapes, parts, medium_types[0], skinny_parts[reserved:],
                    width, height, margin, gap, deadline)
                if len(completed) == len(parts):
                    best = completed
                    attempts = 1
                    structured_complete = True
    while time.monotonic() < search_deadline:
        if structured_complete:
            break
        if attempts < len(starting_orders):
            order, prefer_top = starting_orders[attempts]
            rotations = [((3 if part[2] % 2 == 0 else 1) if part[0] == largest else
                          pattern[part[0]][0][0]) for part in parts] if attempts == 0 and pattern else [rng.randrange(4) for _ in parts]
        elif best_order and len(best) == len(parts) and rng.random() < 0.8:
            order, rotations = best_order.copy(), best_rotations.copy()
            prefer_top = best_top if rng.random() < 0.8 else not best_top
            if len(order) > 1:
                for _ in range(rng.randint(1, 3)):
                    a, b = rng.sample(range(len(order)), 2)
                    order[a], order[b] = order[b], order[a]
            if rng.random() < 0.5:
                rotations[rng.randrange(len(parts))] = rng.randrange(4)
        else:
            order = rng.sample(parts, len(parts))
            rotations = [rng.randrange(4) for _ in parts]
            prefer_top = rng.random() < 0.5
        candidate = layout(order, rotations, pattern if attempts == 0 else None, prefer_top)

        def reusable_area(items):
            right = max((part[4] + part[7] for part in items), default=0)
            top = max((part[5] + part[8] for part in items), default=0)
            return max(width * (height - top), height * (width - right))

        if len(candidate) > len(best) or (len(candidate) == len(best) and
                                         reusable_area(candidate) > reusable_area(best) + 0.001):
            best, best_order, best_rotations, best_top = candidate, order.copy(), rotations.copy(), prefer_top
        attempts += 1
        if attempts >= 40:
            break

    # Greedy contacts can leave a few millimetres between already placed
    # profiles. Compact each complete result against the exact geometry,
    # alternating left and down without moving any piece to the right.
    if len(best) == len(parts) and not structured_complete:
        compacted = best.copy()
        compact_deadline = deadline - min(8, postprocess_budget * 0.6)
        top_area = width * (height - max(item[5] + item[8] for item in best))
        right_area = height * (width - max(item[4] + item[7] for item in best))
        for axis in ((5, 4, 5) if top_area >= right_area else (4, 5, 4)):
            for index in sorted(range(len(compacted)), key=lambda i: compacted[i][axis]):
                if time.monotonic() >= compact_deadline:
                    break
                item = compacted[index]
                current = item[axis]
                if current <= margin + 0.001:
                    continue
                polygon = shapes[item[0]][2][item[3]][0]

                def feasible(coordinate):
                    x, y = (coordinate, item[5]) if axis == 4 else (item[4], coordinate)
                    world = translate(polygon, x, y)
                    for other_index, other in enumerate(compacted):
                        if other_index == index:
                            continue
                        if (x + item[7] + gap <= other[4] + 0.001 or other[4] + other[7] + gap <= x + 0.001 or
                                y + item[8] + gap <= other[5] + 0.001 or other[5] + other[8] + gap <= y + 0.001):
                            continue
                        if world.relate_pattern(other[6], "T********") or (gap and world.distance(other[6]) < gap + CLEARANCE_EPS):
                            return False
                    return True

                valid = current
                invalid = None
                probe = max(margin, current - 20)
                while time.monotonic() < compact_deadline and probe >= margin:
                    if not feasible(probe):
                        invalid = probe
                        break
                    valid = probe
                    if probe == margin:
                        break
                    probe = max(margin, probe - 20)
                if invalid is not None:
                    for _ in range(12):
                        if time.monotonic() >= compact_deadline:
                            break
                        middle = (valid + invalid) / 2
                        if feasible(middle):
                            valid = middle
                        else:
                            invalid = middle
                if valid < current - 0.001:
                    x, y = (valid, item[5]) if axis == 4 else (item[4], valid)
                    moved = list(item)
                    moved[4], moved[5], moved[6] = x, y, translate(polygon, x, y)
                    compacted[index] = tuple(moved)
        refined = (relocate_topmost(compacted, shapes, contacts, width, height, margin, gap, deadline)
                   if top_area >= right_area else
                   relocate_rightmost(compacted, shapes, contacts, width, height, margin, gap, deadline))
        if reusable_area(refined) >= reusable_area(best) - 0.001:
            best = refined
    return {
        "pecas_posicionadas": [{"id": item[1], "instancia": item[2], "x": round(item[4], 3),
                               "y": round(item[5], 3), "rotacao_graus": ANGLES[item[3]],
                               "contornos": shapes[item[0]][0]} for item in best],
        "tentativas": attempts,
    }


if __name__ == "__main__":
    try:
        seconds = float(sys.argv[1]) if len(sys.argv) > 1 else 25
        print(json.dumps(solve(json.load(sys.stdin), seconds), ensure_ascii=True))
    except (KeyError, TypeError, ValueError) as error:
        print(json.dumps({"erro": str(error)}, ensure_ascii=True))
        sys.exit(1)
