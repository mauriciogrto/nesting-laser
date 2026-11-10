#!/usr/bin/env python3
"""Apply a signed kerf offset to nested closed polygons received as JSON on stdin."""

from __future__ import annotations

import json
import math
import sys

import pyclipper


SCALE = 100_000  # 0.00001 mm integer precision for Clipper.
JOIN_TYPES = {"miter": pyclipper.JT_MITER, "round": pyclipper.JT_ROUND, "square": pyclipper.JT_SQUARE}


def validate_polygon(contour: object) -> list[tuple[int, int]]:
    if not isinstance(contour, list) or len(contour) < 3:
        raise ValueError("Cada contorno deve ter pelo menos tres pontos")
    result = []
    for point in contour:
        if not isinstance(point, list) or len(point) != 2 or not all(isinstance(n, (int, float)) and math.isfinite(n) for n in point):
            raise ValueError("Ponto invalido no contorno")
        result.append((round(point[0] * SCALE), round(point[1] * SCALE)))
    if result[0] == result[-1]:
        result.pop()
    if len(set(result)) < 3 or abs(pyclipper.Area(result)) < 1:
        raise ValueError("Contorno degenerado ou de area zero")
    if len(pyclipper.SimplifyPolygon(result, pyclipper.PFT_EVENODD)) != 1:
        raise ValueError("Contorno autointersectante ou invalido")
    return result


def apply_offset(payload: dict) -> dict:
    if not isinstance(payload, dict) or not isinstance(payload.get("contornos"), list) or not payload["contornos"]:
        raise ValueError("JSON deve conter contornos nao vazios")
    value = payload.get("offset_mm")
    if not isinstance(value, (int, float)) or not math.isfinite(value):
        raise ValueError("offset_mm deve ser um numero finito")
    join = payload.get("join_type", "miter")
    if join not in JOIN_TYPES:
        raise ValueError("join_type deve ser miter, round ou square")
    contours = [validate_polygon(contour) for contour in payload["contornos"]]
    areas = [abs(pyclipper.Area(contour)) for contour in contours]
    parents: list[int | None] = []
    for i, contour in enumerate(contours):
        enclosing = [j for j, other in enumerate(contours) if j != i and areas[j] > areas[i] and pyclipper.PointInPolygon(contour[0], other) == 1]
        parents.append(min(enclosing, key=lambda j: areas[j]) if enclosing else None)

    def depth(index: int) -> int:
        current = parents[index]
        count = 0
        while current is not None:
            count += 1
            current = parents[current]
        return count

    output_by_input: list[list[list[tuple[int, int]]]] = []
    for i, contour in enumerate(contours):
        delta = float(value) * SCALE * (-1 if depth(i) % 2 else 1)
        if delta == 0:
            result = [contour]
        else:
            offsetter = pyclipper.PyclipperOffset(miter_limit=2.0)
            offsetter.AddPath(contour, JOIN_TYPES[join], pyclipper.ET_CLOSEDPOLYGON)
            result = offsetter.Execute(delta)
        if not result:
            raise ValueError(f"Contorno {i + 1} colapsou apos o offset")
        output_by_input.append(result)

    # A hole must remain fully enclosed by at least one corresponding outer polygon.
    for i, parent in enumerate(parents):
        if parent is not None and depth(i) % 2 == 1:
            if not any(all(pyclipper.PointInPolygon(point, outside) == 1 for polygon in output_by_input[i] for point in polygon)
                       for outside in output_by_input[parent]):
                raise ValueError(f"Furo {i + 1} ultrapassou o contorno externo apos o offset")

    result = []
    for polygons in output_by_input:
        for polygon in polygons:
            result.append([[round(x / SCALE, 6), round(y / SCALE, 6)] for x, y in polygon])
    return {"contornos": result}


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        print(json.dumps(apply_offset(payload), ensure_ascii=True, allow_nan=False))
        return 0
    except Exception as error:
        print(json.dumps({"erro": str(error)}, ensure_ascii=True))
        return 1


if __name__ == "__main__":
    sys.exit(main())
