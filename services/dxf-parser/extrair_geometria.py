#!/usr/bin/env python3
"""Extract closed 2D cut contours from a DXF as JSON on stdout."""

from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path

import ezdxf
from ezdxf.path import make_path


ENTITY_TYPES = {"LINE", "LWPOLYLINE", "POLYLINE", "ARC", "CIRCLE", "SPLINE"}
# DXF INSUNITS to millimetres. Unitless (0) is treated as mm for shop drawings.
MM_PER_UNIT = {0: 1.0, 1: 25.4, 2: 304.8, 4: 1.0, 5: 10.0, 6: 1000.0, 8: 0.0000254, 9: 0.0254, 10: 914.4, 14: 100.0}


def near(a: list[float], b: list[float], tolerance: float) -> bool:
    return math.dist(a, b) <= tolerance


def polygon_area(points: list[list[float]]) -> float:
    return 0.5 * sum(
        points[i][0] * points[(i + 1) % len(points)][1]
        - points[(i + 1) % len(points)][0] * points[i][1]
        for i in range(len(points))
    )


def clean_polygon(points: list[list[float]], tolerance: float) -> list[list[float]]:
    cleaned: list[list[float]] = []
    for point in points:
        if not cleaned or not near(cleaned[-1], point, tolerance * 0.01):
            cleaned.append(point)
    if len(cleaned) > 1 and near(cleaned[0], cleaned[-1], tolerance):
        cleaned.pop()
    if len(cleaned) < 3 or abs(polygon_area(cleaned)) <= tolerance * tolerance:
        raise ValueError("Contorno fechado degenerado ou com area zero")
    return [[round(x, 6), round(y, 6)] for x, y in cleaned]


def stitch_segments(segments: list[list[list[float]]], tolerance: float) -> list[list[list[float]]]:
    polygons: list[list[list[float]]] = []
    while segments:
        chain = segments.pop(0)
        while not near(chain[0], chain[-1], tolerance):
            found = False
            for index, segment in enumerate(segments):
                if near(chain[-1], segment[0], tolerance):
                    chain += segment[1:]
                elif near(chain[-1], segment[-1], tolerance):
                    chain += list(reversed(segment[:-1]))
                elif near(chain[0], segment[-1], tolerance):
                    chain = segment[:-1] + chain
                elif near(chain[0], segment[0], tolerance):
                    chain = list(reversed(segment[1:])) + chain
                else:
                    continue
                segments.pop(index)
                found = True
                break
            if not found:
                start = chain[0]
                end = chain[-1]
                raise ValueError(
                    "Contorno aberto ou desconectado: "
                    f"início ({start[0]:.3f}, {start[1]:.3f}) mm e "
                    f"fim ({end[0]:.3f}, {end[1]:.3f}) mm não se conectam "
                    f"na tolerância de {tolerance:.3f} mm. "
                    "Feche ou remova linhas soltas no DXF"
                )
        polygons.append(clean_polygon(chain, tolerance))
    return polygons


def extract(file_path: Path, tolerance_mm: float = 0.05) -> dict:
    if tolerance_mm <= 0 or not math.isfinite(tolerance_mm):
        raise ValueError("A tolerancia deve ser um numero positivo")
    if not file_path.is_file() or file_path.suffix.lower() != ".dxf":
        raise ValueError("Informe um arquivo .dxf existente")

    drawing = ezdxf.readfile(file_path)
    audit = drawing.audit()
    if audit.has_errors:
        raise ValueError("DXF corrompido: erros de auditoria no desenho")
    units = int(drawing.header.get("$INSUNITS", 0))
    if units not in MM_PER_UNIT:
        raise ValueError(f"Unidade DXF nao suportada (INSUNITS={units}); use milimetros")
    scale = MM_PER_UNIT[units]
    tolerance_in_drawing = tolerance_mm / scale

    polygons: list[list[list[float]]] = []
    segments: list[list[list[float]]] = []
    for entity in drawing.modelspace():
        if entity.dxftype() not in ENTITY_TYPES:
            continue
        path = make_path(entity)
        for subpath in path.sub_paths() if path.has_sub_paths else [path]:
            points = [[float(p.x) * scale, float(p.y) * scale] for p in subpath.flattening(tolerance_in_drawing, segments=8)]
            if len(points) < 2:
                continue
            if subpath.is_closed or near(points[0], points[-1], tolerance_mm):
                polygons.append(clean_polygon(points, tolerance_mm))
            else:
                segments.append(points)

    polygons.extend(stitch_segments(segments, tolerance_mm))
    if not polygons:
        raise ValueError("Nenhum contorno fechado encontrado no modelspace")
    xs = [point[0] for polygon in polygons for point in polygon]
    ys = [point[1] for polygon in polygons for point in polygon]
    return {
        "bounding_box": {
            "largura_mm": round(max(xs) - min(xs), 6),
            "altura_mm": round(max(ys) - min(ys), 6),
        },
        "contornos": polygons,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Extrai contornos fechados de um DXF em JSON")
    parser.add_argument("arquivo", type=Path)
    parser.add_argument("--tolerancia-mm", type=float, default=0.05)
    args = parser.parse_args()
    try:
        result = extract(args.arquivo, args.tolerancia_mm)
        print(json.dumps(result, ensure_ascii=True, allow_nan=False))
        return 0
    except Exception as error:
        print(json.dumps({"erro": str(error)}, ensure_ascii=True))
        return 1


if __name__ == "__main__":
    sys.exit(main())
