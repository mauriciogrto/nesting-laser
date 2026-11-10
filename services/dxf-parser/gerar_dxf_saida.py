#!/usr/bin/env python3
"""Create a final DXF from order-specific compensated contours received on stdin."""

from __future__ import annotations

import argparse
import json
import math
import sys
from pathlib import Path

import ezdxf


def valid_ring(points: object) -> list[list[float]]:
    if not isinstance(points, list) or len(points) < 3:
        raise ValueError("Contorno precisa de pelo menos tres pontos")
    result = []
    for point in points:
        if not isinstance(point, list) or len(point) != 2 or not all(isinstance(value, (int, float)) and math.isfinite(value) for value in point):
            raise ValueError("Ponto invalido no contorno")
        result.append([float(point[0]), float(point[1])])
    return result


def transform(contours: list, x: float, y: float, degrees: float) -> list:
    radians = math.radians(degrees)
    cosine, sine = math.cos(radians), math.sin(radians)
    rotated = [
        [[point[0] * cosine - point[1] * sine, point[0] * sine + point[1] * cosine] for point in valid_ring(contour)]
        for contour in contours
    ]
    min_x = min(point[0] for point in rotated[0])
    min_y = min(point[1] for point in rotated[0])
    return [
        [[round(point[0] - min_x + x, 6), round(point[1] - min_y + y, 6)] for point in contour]
        for contour in rotated
    ]


def generate(payload: dict, output: Path) -> dict:
    # Import locally: the CNC planner imports transform() from this module.
    # Both exports must use one physical cutting plan and one cut sequence.
    from gerar_cnc_saida import plan

    if not isinstance(payload, dict) or not isinstance(payload.get("chapa"), dict) or not isinstance(payload.get("pecas_posicionadas"), list):
        raise ValueError("JSON deve conter chapa e pecas_posicionadas")
    sheet = payload["chapa"]
    width, height = float(sheet["largura_mm"]), float(sheet["altura_mm"])
    if not math.isfinite(width) or not math.isfinite(height) or width <= 0 or height <= 0:
        raise ValueError("Dimensoes de chapa invalidas")
    outline = valid_ring(sheet["contorno"]) if sheet.get("contorno") else [[0, 0], [width, 0], [width, height], [0, height]]

    drawing = ezdxf.new("R2010")
    drawing.header["$INSUNITS"] = 4
    drawing.header["$PDMODE"] = 34
    drawing.header["$PDSIZE"] = 1.5
    drawing.layers.new("CHAPA", dxfattribs={"color": 8})
    drawing.layers.new("PECAS", dxfattribs={"color": 1})
    drawing.layers.new("PERFURACAO", dxfattribs={"color": 5})
    drawing.layers.new("LEAD_IN", dxfattribs={"color": 2})
    drawing.layers.new("LEAD_OUT", dxfattribs={"color": 2})
    drawing.layers.new("TABS", dxfattribs={"color": 3})
    modelspace = drawing.modelspace()
    modelspace.add_lwpolyline(outline, close=True, dxfattribs={"layer": "CHAPA"})
    paths = plan(payload)["trajetos_cnc"]
    for path in paths:
        modelspace.add_point(path["entrada"][0], dxfattribs={"layer": "PERFURACAO"})
        modelspace.add_line(path["entrada"][0], path["entrada"][1], dxfattribs={"layer": "LEAD_IN"})
        if path["saida"][0] != path["saida"][1]:
            modelspace.add_line(path["saida"][0], path["saida"][1], dxfattribs={"layer": "LEAD_OUT"})
        cut = path["corte"]
        tab = path["tab"]
        modelspace.add_lwpolyline(cut if tab else cut[:-1], close=not bool(tab), dxfattribs={"layer": "PECAS"})
        if tab:
            modelspace.add_lwpolyline(tab, close=False, dxfattribs={"layer": "TABS"})
    output.parent.mkdir(parents=True, exist_ok=True)
    drawing.saveas(output)
    return {"arquivo": str(output), "pecas": len(payload["pecas_posicionadas"]),
            "trajetos": len(paths), "tabs": sum(bool(path["tab"]) for path in paths)}


def main() -> int:
    parser = argparse.ArgumentParser(description="Gera DXF final de um arranjo")
    parser.add_argument("saida", type=Path)
    args = parser.parse_args()
    try:
        print(json.dumps(generate(json.load(sys.stdin), args.saida), ensure_ascii=True))
        return 0
    except Exception as error:
        print(json.dumps({"erro": str(error)}, ensure_ascii=True))
        return 1


if __name__ == "__main__":
    sys.exit(main())
