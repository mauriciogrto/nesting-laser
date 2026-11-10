#!/usr/bin/env python3
"""Plan preview paths and write CNC in the dialect of the supplied PPR Sirius example."""

from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path

import pyclipper

from gerar_dxf_saida import transform, valid_ring


SCALE = 1000


def as_int(point: list[float]) -> tuple[int, int]:
    return round(point[0] * SCALE), round(point[1] * SCALE)


def inside(point: list[float], ring: list[tuple[int, int]]) -> bool:
    return pyclipper.PointInPolygon(as_int(point), ring) == 1


def on_or_inside(point: list[float], ring: list[tuple[int, int]]) -> bool:
    return pyclipper.PointInPolygon(as_int(point), ring) != 0


def material_at(point: list[float], pieces: list[list[list[tuple[int, int]]]]) -> bool:
    for rings in pieces:
        if on_or_inside(point, rings[0]) and not any(inside(point, hole) for hole in rings[1:]):
            return True
    return False


def distance_to_segment(point: list[float], a: tuple[int, int], b: tuple[int, int]) -> float:
    ax, ay = a[0] / SCALE, a[1] / SCALE
    bx, by = b[0] / SCALE, b[1] / SCALE
    dx, dy = bx - ax, by - ay
    projection = max(0.0, min(1.0, ((point[0] - ax) * dx + (point[1] - ay) * dy) / (dx * dx + dy * dy)))
    return math.hypot(point[0] - ax - projection * dx, point[1] - ay - projection * dy)


def clear_lead(start: list[float], end: list[float], sheet: list[tuple[int, int]],
               pieces: list[list[list[tuple[int, int]]]], margin: float,
               width: float, height: float) -> bool:
    distance = math.dist(start, end)
    steps = max(8, math.ceil(distance / 0.25))
    for index in range(steps):
        t = index / steps
        point = [start[0] * (1 - t) + end[0] * t, start[1] * (1 - t) + end[1] * t]
        if (point[0] < margin - 1e-6 or point[1] < margin - 1e-6 or
                point[0] > width - margin + 1e-6 or point[1] > height - margin + 1e-6 or
                not on_or_inside(point, sheet) or material_at(point, pieces)):
            return False
        if margin > 0 and any(distance_to_segment(point, a, sheet[(edge + 1) % len(sheet)]) < margin - 0.001
                              for edge, a in enumerate(sheet)):
            return False
    return True


def lead_for(ring: list[list[float]], sheet: list[tuple[int, int]],
             pieces: list[list[list[tuple[int, int]]]], margin: float,
             width: float, height: float, lead_length: float) -> tuple[list[float], list[float], int]:
    area_sign = 1 if pyclipper.Area([as_int(point) for point in ring]) >= 0 else -1
    corners = []
    for index, cut in enumerate(ring):
        previous, following = ring[index - 1], ring[(index + 1) % len(ring)]
        before = [previous[0] - cut[0], previous[1] - cut[1]]
        after = [following[0] - cut[0], following[1] - cut[1]]
        before_length, after_length = math.hypot(*before), math.hypot(*after)
        if min(before_length, after_length) < 0.01:
            continue
        direction = [before[0] / before_length + after[0] / after_length,
                     before[1] / before_length + after[1] / after_length]
        size = math.hypot(*direction)
        if size < 0.05:
            continue
        turn = ((cut[0] - previous[0]) * (following[1] - cut[1])
                - (cut[1] - previous[1]) * (following[0] - cut[0]))
        sharpness = abs(turn) / (before_length * after_length)
        concave = turn * area_sign < 0
        corners.append((not concave, -sharpness, index, [direction[0] / size, direction[1] / size]))
    # Prefer re-entrant corners, then sharp corners. A pierce is never placed
    # on an exposed straight-edge midpoint. Both bisector directions are
    # checked because hole winding and DXF source winding can differ.
    for _, _, index, direction in sorted(corners):
        cut = ring[index]
        for sign in (1, -1):
            start = [cut[0] + direction[0] * lead_length * sign,
                     cut[1] + direction[1] * lead_length * sign]
            if clear_lead(start, cut, sheet, pieces, margin, width, height):
                return start, cut, index
    raise ValueError("Não há espaço livre para uma entrada de corte segura. Reposicione as peças.")


def contour_path(ring: list[list[float]], start_index: int, tab_size: float) -> tuple[list[list[float]], list[list[float]]]:
    full = [ring[(start_index + step) % len(ring)] for step in range(len(ring) + 1)]
    if tab_size <= 0:
        return full, []
    perimeter = sum(math.dist(a, b) for a, b in zip(full, full[1:]))
    if tab_size >= perimeter / 4:
        raise ValueError("A tab é grande demais para o contorno externo desta peça.")
    cut_length = perimeter - tab_size
    progress = 0.0
    for index, (a, b) in enumerate(zip(full, full[1:])):
        length = math.dist(a, b)
        if progress + length >= cut_length - 1e-9:
            fraction = (cut_length - progress) / length
            end = [a[0] + (b[0] - a[0]) * fraction, a[1] + (b[1] - a[1]) * fraction]
            return [*full[:index + 1], end], [end, *full[index + 1:]]
        progress += length
    raise ValueError("Não foi possível posicionar a tab no contorno.")


def rounded(point: list[float]) -> list[float]:
    return [round(point[0], 3), round(point[1], 3)]


def circle_from_three(a: list[float], b: list[float], c: list[float]) -> tuple[float, float] | None:
    ax, ay = a[0] - b[0], a[1] - b[1]
    cx, cy = c[0] - b[0], c[1] - b[1]
    determinant = 2 * (ax * cy - ay * cx)
    if abs(determinant) < 1e-9:
        return None
    aa, cc = ax * ax + ay * ay, cx * cx + cy * cy
    return (b[0] + (aa * cy - cc * ay) / determinant,
            b[1] + (cc * ax - aa * cx) / determinant)


def arc_candidate(points: list[list[float]]) -> dict | None:
    if len(points) < 4:
        return None
    middle = len(points) // 2
    center = circle_from_three(points[0], points[middle], points[-1])
    if center is None:
        return None
    radius = math.dist(points[0], center)
    if radius > 10000 or any(abs(math.dist(point, center) - radius) > 0.035 for point in points):
        return None
    cross = ((points[1][0] - points[0][0]) * (points[2][1] - points[1][1])
             - (points[1][1] - points[0][1]) * (points[2][0] - points[1][0]))
    direction = 1 if cross > 0 else -1
    if abs(cross) < 1e-8:
        return None
    angles = [math.atan2(point[1] - center[1], point[0] - center[0]) for point in points]
    sweep = 0.0
    for before, after in zip(angles, angles[1:]):
        change = (after - before) % (2 * math.pi) if direction > 0 else (before - after) % (2 * math.pi)
        if change <= 1e-7 or change > math.pi / 2:
            return None
        sweep += change
    if sweep > math.pi + 0.01 or radius * (1 - math.cos(sweep / 2)) < 0.035:
        return None
    return {"code": "G03" if direction > 0 else "G02", "point": points[-1],
            "i": round(center[0] - points[0][0], 3), "j": round(center[1] - points[0][1], 3)}


def cut_moves(points: list[list[float]]) -> list[dict]:
    moves = []
    start = 0
    while start < len(points) - 1:
        best = None
        maximum = min(len(points), start + 90)
        for end in range(start + 3, maximum):
            candidate = arc_candidate(points[start:end + 1])
            if candidate:
                best = (end, candidate)
            elif best:
                break
        if best:
            start, move = best
            moves.append(move)
        else:
            start += 1
            moves.append({"code": "G01", "point": points[start]})
    return moves


def plan(payload: dict) -> dict:
    if not isinstance(payload, dict) or not isinstance(payload.get("chapa"), dict) or not isinstance(payload.get("pecas_posicionadas"), list):
        raise ValueError("JSON deve conter chapa e pecas_posicionadas")
    sheet_data = payload["chapa"]
    width, height = float(sheet_data["largura_mm"]), float(sheet_data["altura_mm"])
    if not all(math.isfinite(n) and n > 0 for n in (width, height)):
        raise ValueError("Dimensões da chapa inválidas")
    sheet = valid_ring(sheet_data["contorno"]) if sheet_data.get("contorno") else [[0, 0], [width, 0], [width, height], [0, height]]
    feed = float(payload.get("feed_mm_min", 400))
    if not math.isfinite(feed) or feed <= 0 or feed > 100000:
        raise ValueError("Velocidade de corte inválida")
    margin = float(payload.get("margem_borda_mm", 0))
    if not math.isfinite(margin) or margin < 0 or margin > 100:
        raise ValueError("Margem de segurança inválida")
    lead_length = float(payload.get("lead_in_mm", 2))
    if not math.isfinite(lead_length) or lead_length <= 0 or lead_length > 100:
        raise ValueError("Comprimento de lead-in inválido")
    use_tabs = bool(payload.get("usar_tabs", False))
    tab_size = float(payload.get("tamanho_tab_mm", 1))
    if use_tabs and (not math.isfinite(tab_size) or tab_size <= 0 or tab_size > 100):
        raise ValueError("Tamanho de tab inválido")

    transformed = []
    for item in payload["pecas_posicionadas"]:
        contours = item.get("contornos") or ([item["contorno"]] if item.get("contorno") else None)
        if not contours:
            raise ValueError("Peça sem contornos compensados")
        rings = transform(contours, float(item["x"]), float(item["y"]), float(item["rotacao_graus"]))
        rings.sort(key=lambda ring: abs(pyclipper.Area([as_int(point) for point in ring])), reverse=True)
        transformed.append(rings)
    sheet_int = [as_int(point) for point in sheet]
    transformed_int = [[[as_int(point) for point in ring] for ring in rings] for rings in transformed]

    paths = []
    for item, rings in zip(payload["pecas_posicionadas"], transformed):
        for contour_index in [*range(1, len(rings)), 0]:
            ring = rings[contour_index]
            lead, cut, start_index = lead_for(ring, sheet_int, transformed_int, margin, width, height, lead_length)
            cut_path, tab_path = contour_path(ring, start_index, tab_size if use_tabs and contour_index == 0 else 0)
            cut_points = [rounded(point) for point in cut_path]
            end = cut_points[-1]
            paths.append({
                "id": item.get("id"), "instancia": item.get("instancia", 0),
                "tipo": "externo" if contour_index == 0 else "furo",
                "entrada": [rounded(lead), rounded(cut)],
                "corte": cut_points,
                "movimentos": cut_moves(cut_points),
                "saida": [end, end] if tab_path else [end, rounded(lead)],
                "tab": [rounded(point) for point in tab_path],
                "area_externa_mm2": abs(pyclipper.Area([as_int(point) for point in rings[0]])) / SCALE ** 2,
                "_ring": [as_int(point) for point in ring],
            })
    # Validated contours never cross. Containment depth therefore gives the
    # release dependencies: a nested part (and its own holes) must be cut BEFORE
    # the host hole is released. Otherwise the part falls with an uncut slug.
    # At equal depth, holes precede small-to-large external releases.
    for path in paths:
        path["_depth"] = sum(pyclipper.PointInPolygon(path["_ring"][0], other["_ring"]) == 1
                             for other in paths if other is not path)
    paths.sort(key=lambda path: (-path["_depth"], 0 if path["tipo"] == "furo" else 1,
                                  path["area_externa_mm2"] if path["tipo"] == "externo" else 0))
    for path in paths:
        del path["_depth"], path["_ring"]
    return {"trajetos_cnc": paths, "feed_mm_min": feed}


def number(value: float) -> str:
    return f"{value:.3f}".rstrip("0").rstrip(".") or "0"


def command(code: str, point: list[float], feed: float | None = None,
            i: float | None = None, j: float | None = None) -> str:
    line = f"{code} X{number(point[0])} Y{number(point[1])}"
    if i is not None and j is not None:
        line += f" I{number(i)} J{number(j)}"
    if feed is not None:
        line += f" F{number(feed)}"
    return line


def generate(payload: dict, output: Path) -> dict:
    result = plan(payload)
    feed = result["feed_mm_min"]
    lines = ["%", "G90", "G71", "G40", "(Nesting Laser)"]
    for path in result["trajetos_cnc"]:
        lines.append(command("G00", path["entrada"][0]))
        lines.append("M07")
        lines.append(command("G01", path["entrada"][1], feed))
        lines.extend(command(move["code"], move["point"], feed, move.get("i"), move.get("j"))
                     for move in path["movimentos"])
        if path["saida"][1] != path["saida"][0]:
            lines.append(command("G01", path["saida"][1], feed))
        lines.append("M08")
    lines.append("M02")
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text("\n".join(lines) + "\n", encoding="ascii")
    return {"arquivo": str(output), "trajetos": len(result["trajetos_cnc"])}


def main() -> int:
    parser = argparse.ArgumentParser(description="Gera CNC final ou trajetos para o preview")
    parser.add_argument("saida", nargs="?", type=Path)
    parser.add_argument("--preview", action="store_true")
    args = parser.parse_args()
    try:
        payload = json.load(sys.stdin)
        if args.preview:
            print(json.dumps(plan(payload), ensure_ascii=True, allow_nan=False))
        elif args.saida:
            print(json.dumps(generate(payload, args.saida), ensure_ascii=True))
        else:
            raise ValueError("Informe o arquivo de saída CNC")
        return 0
    except Exception as error:
        print(json.dumps({"erro": str(error)}, ensure_ascii=True))
        return 1


if __name__ == "__main__":
    sys.exit(main())
