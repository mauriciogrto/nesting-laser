import unittest

from gerar_pdf_arranjo import render, report_metrics


class PdfArranjoTest(unittest.TestCase):
    def test_renders_saved_outline_and_hole_as_a_pdf(self):
        payload = {
            "pedido_id": 5, "nome": "Arranjo real", "material": "Aço carbono",
            "espessura_mm": 1, "area_utilizada_pct": 6.25,
            "chapa": {"largura_mm": 100, "altura_mm": 80},
            "pecas_posicionadas": [{
                "id": 1, "nome": "Peça com furo", "x": 10, "y": 12,
                "rotacao_graus": 90,
                "contornos": [
                    [[0, 0], [20, 0], [20, 20], [0, 20]],
                    [[5, 5], [15, 5], [15, 15], [5, 15]],
                ],
            }],
        }
        result = render(payload)
        self.assertTrue(result.startswith(b"%PDF-"))
        self.assertGreater(len(result), 2000)
        metrics = report_metrics(payload)
        self.assertEqual(metrics["piece_count"], 1)
        self.assertEqual(metrics["pieces_area"], 300)
        self.assertAlmostEqual(metrics["pieces_percent"], 3.75)
        self.assertAlmostEqual(metrics["scrap_percent"], 96.25)

    def test_report_uses_nominal_contours_and_groups_quantities_by_code(self):
        part = {
            "id": 17, "nome": "EXEMPLO", "x": 10, "y": 10,
            "rotacao_graus": 0, "contornos": [[[0, 0], [22, 0], [22, 22], [0, 22]]],
        }
        payload = {
            "chapa": {"largura_mm": 100, "altura_mm": 100},
            "espessura_mm": 19, "densidade_kg_m3": 7850,
            "pecas_posicionadas": [part, {**part, "x": 40}],
            "catalogo": {"17": {"nome": "EXEMPLO", "largura_mm": 20, "altura_mm": 20,
                                 "contornos_originais": [[[0, 0], [20, 0], [20, 20], [0, 20]]]}},
            "sobra_contorno": [[0, 50], [100, 50], [100, 100], [0, 100]],
        }
        metrics = report_metrics(payload)
        self.assertEqual(metrics["piece_count"], 2)
        self.assertEqual(metrics["groups"][0]["quantity"], 2)
        self.assertEqual(metrics["pieces_area"], 800)
        self.assertAlmostEqual(metrics["pieces_percent"], 8)
        self.assertAlmostEqual(metrics["remnant_percent"], 50)
        self.assertAlmostEqual(metrics["sheet_mass_kg"], 1.4915)
        self.assertAlmostEqual(metrics["pieces_mass_kg"], 0.11932)

    def test_rejects_empty_arrangement(self):
        with self.assertRaisesRegex(ValueError, "sem pecas"):
            render({"chapa": {"largura_mm": 100, "altura_mm": 80},
                    "pecas_posicionadas": []})


if __name__ == "__main__":
    unittest.main()
