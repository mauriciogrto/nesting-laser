#!/usr/bin/env python3
"""Create a printable NestLaser sheet report from the saved CNC geometry."""

from __future__ import annotations

from collections import OrderedDict
import io
import json
import math
import os
import sys

import reportlab
from reportlab.lib import colors
from reportlab.lib.pagesizes import A3, landscape
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

from gerar_dxf_saida import transform, valid_ring


NAVY = colors.HexColor("#1b2431")
PANEL = colors.HexColor("#273142")
BLUE = colors.HexColor("#4880ff")
INK = colors.HexColor("#253247")
MUTED = colors.HexColor("#68778d")
PALE = colors.HexColor("#f4f7fc")
LINE = colors.HexColor("#dce4ef")
FONT_DIRECTORY = os.path.join(os.path.dirname(reportlab.__file__), "fonts")
pdfmetrics.registerFont(TTFont("NestSans", os.path.join(FONT_DIRECTORY, "Vera.ttf")))
pdfmetrics.registerFont(TTFont("NestSans-Bold", os.path.join(FONT_DIRECTORY, "VeraBd.ttf")))


def text(value: object) -> str:
    return str(value if value is not None else "")


def number(value: float, decimals: int = 2) -> str:
    return f"{value:,.{decimals}f}".replace(",", "_").replace(".", ",").replace("_", ".")


def duration(seconds: float) -> str:
    total = max(0, round(seconds))
    return f"{total // 3600:02}:{total // 60 % 60:02}:{total % 60:02}"


def ring_area(ring: list) -> float:
    points = valid_ring(ring)
    return abs(sum(x * points[(i + 1) % len(points)][1] - points[(i + 1) % len(points)][0] * y
                   for i, (x, y) in enumerate(points))) / 2


def ring_length(ring: list) -> float:
    points = valid_ring(ring)
    return sum(math.hypot(points[(i + 1) % len(points)][0] - x,
                          points[(i + 1) % len(points)][1] - y)
               for i, (x, y) in enumerate(points))


def profile_metrics(contours: list) -> tuple[float, float]:
    if not contours:
        return 0.0, 0.0
    areas = sorted((ring_area(ring) for ring in contours), reverse=True)
    return max(0.0, areas[0] - sum(areas[1:])), sum(ring_length(ring) for ring in contours)


def report_metrics(payload: dict) -> dict:
    sheet = payload["chapa"]
    width, height = float(sheet["largura_mm"]), float(sheet["altura_mm"])
    if not all(math.isfinite(value) and value > 0 for value in (width, height)):
        raise ValueError("Dimensoes de chapa invalidas")
    pieces = payload["pecas_posicionadas"]
    if not isinstance(pieces, list) or not pieces:
        raise ValueError("Arranjo sem pecas posicionadas")

    catalog = payload.get("catalogo") or {}
    groups: OrderedDict[str, dict] = OrderedDict()
    bounds = [math.inf, math.inf, -math.inf, -math.inf]
    for piece in pieces:
        contours = piece.get("contornos") or ([piece["contorno"]] if piece.get("contorno") else None)
        if not contours:
            raise ValueError("Peca sem contorno compensado")
        original = catalog.get(str(piece.get("id"))) or {}
        nominal_contours = original.get("contornos_originais") or contours
        area, perimeter = profile_metrics(nominal_contours)
        code = text(original.get("nome") or piece.get("nome") or piece.get("id") or "Peça")
        if code not in groups:
            groups[code] = {
                "code": code, "quantity": 0, "area_unit_mm2": area,
                "perimeter_unit_mm": perimeter,
                "width_mm": float(original.get("largura_mm") or 0),
                "height_mm": float(original.get("altura_mm") or 0),
            }
        groups[code]["quantity"] += 1
        rings = transform(contours, float(piece["x"]), float(piece["y"]), float(piece["rotacao_graus"]))
        for x, y in valid_ring(max(rings, key=ring_area)):
            bounds[0], bounds[1] = min(bounds[0], x), min(bounds[1], y)
            bounds[2], bounds[3] = max(bounds[2], x), max(bounds[3], y)

    sheet_area = width * height
    pieces_area = sum(group["area_unit_mm2"] * group["quantity"] for group in groups.values())
    remnant = payload.get("sobra_contorno")
    remnant_area = ring_area(remnant) if remnant and len(remnant) >= 3 else 0.0
    density = float(payload.get("densidade_kg_m3") or 0)
    thickness = float(payload.get("espessura_mm") or 0)
    mass_factor = density * thickness / 1_000_000_000 if density > 0 and thickness > 0 else None
    return {
        "width": width, "height": height, "sheet_area": sheet_area,
        "pieces_area": pieces_area, "pieces_percent": pieces_area / sheet_area * 100,
        "scrap_percent": max(0, (sheet_area - pieces_area) / sheet_area * 100),
        "remnant_area": remnant_area, "remnant_percent": remnant_area / sheet_area * 100,
        "remnant_width": max(p[0] for p in remnant) - min(p[0] for p in remnant) if remnant else 0,
        "remnant_height": max(p[1] for p in remnant) - min(p[1] for p in remnant) if remnant else 0,
        "occupied_width": bounds[2] - bounds[0], "occupied_height": bounds[3] - bounds[1],
        "groups": list(groups.values()), "piece_count": len(pieces),
        "mass_factor": mass_factor,
        "sheet_mass_kg": sheet_area * mass_factor if mass_factor is not None else None,
        "pieces_mass_kg": pieces_area * mass_factor if mass_factor is not None else None,
    }


def fit_text(document: canvas.Canvas, value: object, max_width: float, font: str, size: float) -> str:
    result = text(value)
    if pdfmetrics.stringWidth(result, font, size) <= max_width:
        return result
    while result and pdfmetrics.stringWidth(result + "...", font, size) > max_width:
        result = result[:-1]
    return result + "..."


def label_value(document: canvas.Canvas, x: float, y: float, label: str, value: str, width: float) -> None:
    document.setFont("NestSans", 8)
    document.setFillColor(MUTED)
    document.drawString(x, y + 13, text(label).upper())
    document.setFont("NestSans-Bold", 10)
    document.setFillColor(INK)
    document.drawString(x, y, fit_text(document, value, width, "NestSans-Bold", 10))


def metric_card(document: canvas.Canvas, x: float, y: float, width: float, title: str, value: str,
                subtitle: str) -> None:
    document.setFillColor(colors.white)
    document.setStrokeColor(LINE)
    document.roundRect(x, y, width, 69, 8, fill=1, stroke=1)
    document.setFont("NestSans-Bold", 8)
    document.setFillColor(MUTED)
    document.drawString(x + 15, y + 49, text(title).upper())
    document.setFont("NestSans-Bold", 20)
    document.setFillColor(INK)
    document.drawString(x + 15, y + 23, text(value))
    document.setFont("NestSans", 7)
    document.setFillColor(MUTED)
    document.drawString(x + 15, y + 10, fit_text(document, subtitle, width - 30, "NestSans", 7))


def draw_outline(document: canvas.Canvas, ring: list, origin_x: float, origin_y: float,
                 scale: float, stroke: colors.Color, thickness: float, fill: colors.Color | None = None) -> None:
    points = valid_ring(ring)
    path = document.beginPath()
    path.moveTo(origin_x + points[0][0] * scale, origin_y + points[0][1] * scale)
    for x, y in points[1:]:
        path.lineTo(origin_x + x * scale, origin_y + y * scale)
    path.close()
    document.setStrokeColor(stroke)
    document.setLineWidth(thickness)
    if fill is not None:
        document.setFillColor(fill)
    document.drawPath(path, stroke=1, fill=int(fill is not None))


def render(payload: dict) -> bytes:
    metrics = report_metrics(payload)
    width, height = metrics["width"], metrics["height"]
    page_width, page_height = landscape(A3)
    buffer = io.BytesIO()
    document = canvas.Canvas(buffer, pagesize=(page_width, page_height), pageCompression=1)
    document.setTitle(text(f"Relatório de corte - Pedido #{payload['pedido_id']}"))
    document.setAuthor("NestLaser")

    document.setFillColor(colors.white)
    document.rect(0, 0, page_width, page_height, fill=1, stroke=0)
    document.setFillColor(NAVY)
    document.rect(0, page_height - 108, page_width, 108, fill=1, stroke=0)
    document.setFillColor(BLUE)
    document.rect(0, page_height - 108, 6, 108, fill=1, stroke=0)
    document.setFillColor(colors.white)
    document.setFont("NestSans-Bold", 12)
    document.drawString(42, page_height - 36, "NEST")
    document.setFillColor(colors.HexColor("#80a6ff"))
    document.drawString(78, page_height - 36, "LASER")
    document.setFillColor(colors.white)
    document.setFont("NestSans-Bold", 23)
    document.drawString(42, page_height - 72, "RELATÓRIO DE CORTE")
    document.setFont("NestSans", 9)
    document.setFillColor(colors.HexColor("#d8e2f2"))
    document.drawString(42, page_height - 91, fit_text(document, payload.get("nome") or "Arranjo sem nome", 550, "NestSans", 9))
    document.setFont("NestSans-Bold", 12)
    document.setFillColor(colors.white)
    document.drawRightString(page_width - 42, page_height - 47,
                             text(f"PEDIDO #{payload['pedido_id']}  /  CHAPA {payload.get('chapa_numero', 1)}"))
    document.setFont("NestSans", 8)
    document.setFillColor(colors.HexColor("#d8e2f2"))
    document.drawRightString(page_width - 42, page_height - 68,
                             text(f"Gerado em {payload.get('gerado_em') or '—'}"))
    if payload.get("exportado_em"):
        document.drawRightString(page_width - 42, page_height - 84,
                                 text(f"Exportado em {payload['exportado_em']}"))

    card_y, gap = page_height - 196, 12
    card_width = (page_width - 84 - gap * 4) / 5
    metric_card(document, 42, card_y, card_width, "Peças na chapa", str(metrics["piece_count"]),
                f"{len(metrics['groups'])} desenhos diferentes")
    metric_card(document, 42 + (card_width + gap), card_y, card_width, "Área das peças",
                f"{number(metrics['pieces_percent'])}%", "Área nominal do material")
    metric_card(document, 42 + 2 * (card_width + gap), card_y, card_width, "Sucata bruta",
                f"{number(metrics['scrap_percent'])}%", "Inclui a parte reaproveitável")
    metric_card(document, 42 + 3 * (card_width + gap), card_y, card_width, "Peso das peças",
                f"{number(metrics['pieces_mass_kg'], 1)} kg" if metrics["pieces_mass_kg"] is not None else "—",
                f"Chapa: {number(metrics['sheet_mass_kg'], 1)} kg" if metrics["sheet_mass_kg"] is not None else "Densidade não cadastrada")
    metric_card(document, 42 + 4 * (card_width + gap), card_y, card_width, "Tempo estimado",
                duration(float(payload.get("tempo_corte_estimado_seg") or 0)), "Corte e deslocamento")

    body_y, body_height, left_x, left_width = 105, page_height - 321, 42, 480
    right_x, right_width = left_x + left_width + 16, page_width - 84 - left_width - 16
    for x, panel_width in ((left_x, left_width), (right_x, right_width)):
        document.setFillColor(colors.white)
        document.setStrokeColor(LINE)
        document.roundRect(x, body_y, panel_width, body_height, 8, fill=1, stroke=1)

    document.setFont("NestSans-Bold", 11)
    document.setFillColor(INK)
    document.drawString(left_x + 18, body_y + body_height - 26, "01   ARRANJO DA CHAPA")
    document.setFont("NestSans", 8)
    document.setFillColor(MUTED)
    document.drawRightString(left_x + left_width - 18, body_y + body_height - 26, "Contornos compensados")
    diagram_x, diagram_y = left_x + 16, body_y + 38
    diagram_width, diagram_height = left_width - 32, body_height - 82
    document.setFillColor(PALE)
    document.roundRect(diagram_x, diagram_y, diagram_width, diagram_height, 5, fill=1, stroke=0)
    scale = min((diagram_width - 30) / width, (diagram_height - 22) / height)
    origin_x = diagram_x + (diagram_width - width * scale) / 2
    origin_y = diagram_y + (diagram_height - height * scale) / 2
    sheet_ring = payload["chapa"].get("contorno") or [[0, 0], [width, 0], [width, height], [0, height]]
    remnant = payload.get("sobra_contorno")
    if remnant and len(remnant) >= 3:
        draw_outline(document, remnant, origin_x, origin_y, scale, BLUE, 0.8,
                     colors.HexColor("#e2ecff"))
    margin = float(payload.get("margem_borda_mm") or 0)
    if margin > 0 and not payload["chapa"].get("contorno") and margin * 2 < min(width, height):
        document.setStrokeColor(colors.HexColor("#a1b4d1"))
        document.setLineWidth(0.5)
        document.setDash(3, 2)
        document.rect(origin_x + margin * scale, origin_y + margin * scale,
                      (width - 2 * margin) * scale, (height - 2 * margin) * scale, fill=0, stroke=1)
        document.setDash()
    draw_outline(document, sheet_ring, origin_x, origin_y, scale, BLUE, 1.3)
    for piece in payload["pecas_posicionadas"]:
        contours = piece.get("contornos") or ([piece["contorno"]] if piece.get("contorno") else None)
        rings = transform(contours, float(piece["x"]), float(piece["y"]), float(piece["rotacao_graus"]))
        for ring in rings:
            draw_outline(document, ring, origin_x, origin_y, scale, INK, 0.55)
    document.setFont("NestSans", 8)
    document.setFillColor(MUTED)
    document.drawString(left_x + 18, body_y + 18, text(f"Chapa: {number(width, 0)} x {number(height, 0)} mm"))
    document.drawRightString(left_x + left_width - 18, body_y + 18,
                             text(f"Ocupação: {number(metrics['occupied_width'], 1)} x {number(metrics['occupied_height'], 1)} mm"))

    document.setFont("NestSans-Bold", 11)
    document.setFillColor(INK)
    document.drawString(right_x + 18, body_y + body_height - 26, "02   ESPECIFICAÇÕES E PEÇAS")
    spec_top = body_y + body_height - 66
    spec_column = (right_width - 54) / 2
    spec = [
        ("Material", payload.get("material") or "—"),
        ("Espessura", f"{number(float(payload.get('espessura_mm') or 0), 2)} mm"),
        ("Chapa", f"{number(width, 0)} x {number(height, 0)} mm"),
        ("Área da chapa", f"{number(metrics['sheet_area'] / 1_000_000, 3)} m²"),
        ("Kerf / folga", f"{number(float(payload.get('kerf_mm') or 0), 2)} / {number(float(payload.get('espacamento_minimo_mm') or 0), 2)} mm"),
        ("Margem / entrada", f"{number(margin, 2)} / {number(float(payload.get('lead_in_mm') or 0), 2)} mm"),
    ]
    for index, (label, value) in enumerate(spec):
        col, row = index % 2, index // 2
        label_value(document, right_x + 18 + col * (spec_column + 16), spec_top - row * 37,
                    label, text(value), spec_column)
    line_y = spec_top - 92
    document.setStrokeColor(LINE)
    document.line(right_x + 18, line_y, right_x + right_width - 18, line_y)
    document.setFont("NestSans-Bold", 9)
    document.setFillColor(INK)
    document.drawString(right_x + 18, line_y - 21, "LISTA DE CORTE")
    document.setFont("NestSans", 8)
    document.setFillColor(MUTED)
    document.drawRightString(right_x + right_width - 18, line_y - 21,
                             text(f"Área nominal: {number(metrics['pieces_area'] / 1_000_000, 3)} m²"))
    table_top = line_y - 47
    document.setFillColor(PALE)
    document.roundRect(right_x + 12, table_top - 3, right_width - 24, 24, 4, fill=1, stroke=0)
    col = [right_x + 20, right_x + 143, right_x + 254, right_x + 325,
           right_x + 364, right_x + 439, right_x + 533]
    document.setFont("NestSans-Bold", 7)
    document.setFillColor(MUTED)
    for x, heading in zip(col, ["CÓDIGO", "MEDIDAS MM", "CORTE MM", "QTD.",
                                "ÁREA CM²", "TOTAL CM²", "PESO KG"]):
        document.drawString(x, table_top + 5, text(heading))
    rows = metrics["groups"]
    for index, group in enumerate(rows[:10]):
        y = table_top - 25 - index * 25
        if index % 2 == 1:
            document.setFillColor(colors.HexColor("#f9fbfe"))
            document.rect(right_x + 13, y - 7, right_width - 26, 24, fill=1, stroke=0)
        document.setStrokeColor(LINE)
        document.line(right_x + 18, y - 8, right_x + right_width - 18, y - 8)
        document.setFillColor(INK)
        document.setFont("NestSans-Bold", 8)
        document.drawString(col[0], y, fit_text(document, group["code"], 116, "NestSans-Bold", 8))
        document.setFont("NestSans", 8)
        dimensions = (f"{number(group['width_mm'], 1)} x {number(group['height_mm'], 1)}"
                      if group["width_mm"] and group["height_mm"] else "—")
        document.drawString(col[1], y, fit_text(document, dimensions, 104, "NestSans", 8))
        document.drawString(col[2], y, number(group["perimeter_unit_mm"], 1))
        document.drawString(col[3], y, str(group["quantity"]))
        document.drawString(col[4], y, number(group["area_unit_mm2"] / 100, 1))
        document.drawString(col[5], y, number(group["area_unit_mm2"] * group["quantity"] / 100, 1))
        document.drawString(col[6], y, number(group["area_unit_mm2"] * group["quantity"] * metrics["mass_factor"], 1)
                            if metrics["mass_factor"] is not None else "—")
    if len(rows) > 10:
        document.setFont("NestSans", 7)
        document.setFillColor(MUTED)
        document.drawString(right_x + 18, body_y + 46, text(f"Mais {len(rows) - 10} desenhos; veja a continuação na próxima página."))
    document.setFont("NestSans-Bold", 8)
    document.setFillColor(INK)
    document.drawString(right_x + 18, body_y + 21, "RETALHO RETANGULAR APROVEITÁVEL")
    document.setFont("NestSans", 8)
    document.drawRightString(right_x + right_width - 18, body_y + 21,
                             text(f"{number(metrics['remnant_width'], 1)} x {number(metrics['remnant_height'], 1)} mm  |  {number(metrics['remnant_percent'])}%"))

    document.setFillColor(PANEL)
    document.rect(0, 0, page_width, 85, fill=1, stroke=0)
    document.setFont("NestSans-Bold", 9)
    document.setFillColor(colors.white)
    document.drawString(42, 59, "LEITURA DO RELATÓRIO")
    document.setFont("NestSans", 8)
    document.setFillColor(colors.HexColor("#d7e2f3"))
    document.drawString(42, 42, "Uso = área nominal das peças / área total da chapa. Sucata bruta inclui o retalho aproveitável.")
    document.drawString(42, 27, "Contornos no desenho já incluem a compensação de kerf; confira o CNC e os parâmetros na máquina antes do corte.")
    document.setFont("NestSans-Bold", 8)
    document.setFillColor(colors.white)
    document.drawRightString(page_width - 42, 59, text(f"RETALHO: {number(metrics['remnant_area'] / 1_000_000, 3)} m²"))
    document.setFont("NestSans", 8)
    document.setFillColor(colors.HexColor("#d7e2f3"))
    continuation_pages = math.ceil(max(0, len(rows) - 10) / 24)
    document.drawRightString(page_width - 42, 27, text(f"NESTLASER  ·  01 / {1 + continuation_pages:02}"))
    document.showPage()

    for page_index in range(continuation_pages):
        document.setFillColor(NAVY)
        document.rect(0, page_height - 95, page_width, 95, fill=1, stroke=0)
        document.setFillColor(colors.white)
        document.setFont("NestSans-Bold", 19)
        document.drawString(42, page_height - 58, "LISTA DE CORTE · CONTINUAÇÃO")
        document.setFont("NestSans", 9)
        document.drawRightString(page_width - 42, page_height - 58,
                                 text(f"Pedido #{payload['pedido_id']} / Chapa {payload.get('chapa_numero', 1)}"))
        for index, group in enumerate(rows[10 + page_index * 24:10 + (page_index + 1) * 24]):
            y = page_height - 135 - index * 27
            document.setFont("NestSans-Bold", 9)
            document.setFillColor(INK)
            document.drawString(42, y, fit_text(document, group["code"], 220, "NestSans-Bold", 9))
            document.setFont("NestSans", 9)
            document.drawString(315, y, f"{group['quantity']} peças")
            document.drawString(460, y, f"{number(group['area_unit_mm2'] * group['quantity'] / 100, 1)} cm²")
            document.setStrokeColor(LINE)
            document.line(42, y - 8, page_width - 42, y - 8)
        document.setFont("NestSans", 8)
        document.setFillColor(MUTED)
        document.drawRightString(page_width - 42, 25, text(f"NESTLASER  ·  {page_index + 2:02} / {1 + continuation_pages:02}"))
        document.showPage()

    document.save()
    return buffer.getvalue()


if __name__ == "__main__":
    try:
        sys.stdout.buffer.write(render(json.load(sys.stdin)))
    except (KeyError, TypeError, ValueError) as error:
        print(f"Falha ao gerar PDF: {error}", file=sys.stderr)
        sys.exit(1)

