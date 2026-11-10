import importlib.util
import tempfile
import unittest
from pathlib import Path

import ezdxf

MODULE_PATH = Path(__file__).resolve().parents[1] / "gerar_dxf_saida.py"
spec = importlib.util.spec_from_file_location("gerar_dxf_saida", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class GenerateDxfTest(unittest.TestCase):
    def test_compensated_outline_and_sheet_are_exported_in_separate_layers(self):
        payload = {
            "chapa": {"largura_mm": 100, "altura_mm": 100},
            "pecas_posicionadas": [{
                "contornos": [[[0, 0], [19.9, 0], [19.9, 9.9], [0, 9.9]]],
                "x": 30, "y": 40, "rotacao_graus": 90,
            }],
        }
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / "resultado.dxf"
            module.generate(payload, output)
            drawing = ezdxf.readfile(output)
            polylines = list(drawing.modelspace().query("LWPOLYLINE"))
            self.assertEqual(len(polylines), 2)
            self.assertEqual([entity.dxf.layer for entity in polylines], ["CHAPA", "PECAS"])
            points = [(p[0], p[1]) for p in polylines[1]]
            self.assertAlmostEqual(max(x for x, _ in points) - min(x for x, _ in points), 9.9)
            self.assertAlmostEqual(max(y for _, y in points) - min(y for _, y in points), 19.9)
            self.assertAlmostEqual(min(x for x, _ in points), 30)
            self.assertAlmostEqual(min(y for _, y in points), 40)
            self.assertEqual(drawing.header["$INSUNITS"], 4)
            self.assertEqual(len(list(drawing.modelspace().query('LINE[layer=="LEAD_OUT"]'))), 1)

    def test_technical_layers_show_pierce_lead_in_and_open_tab(self):
        payload = {"chapa": {"largura_mm": 100, "altura_mm": 100},
                   "lead_in_mm": 2, "usar_tabs": True, "tamanho_tab_mm": 1.5,
                   "pecas_posicionadas": [{"id": 1, "instancia": 0, "x": 20, "y": 20,
                                           "rotacao_graus": 0,
                                           "contornos": [[[0, 0], [20, 0], [20, 20], [0, 20]]]}]}
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / "tecnico.dxf"
            module.generate(payload, output)
            drawing = ezdxf.readfile(output)
            model = drawing.modelspace()
            self.assertEqual(len(list(model.query('POINT[layer=="PERFURACAO"]'))), 1)
            self.assertEqual(len(list(model.query('LINE[layer=="LEAD_IN"]'))), 1)
            self.assertEqual(len(list(model.query('LINE[layer=="LEAD_OUT"]'))), 0)
            tabs = list(model.query('LWPOLYLINE[layer=="TABS"]'))
            self.assertEqual(len(tabs), 1)
            self.assertFalse(tabs[0].closed)
            cuts = list(model.query('LWPOLYLINE[layer=="PECAS"]'))
            self.assertEqual(len(cuts), 1)
            self.assertFalse(cuts[0].closed)


if __name__ == "__main__":
    unittest.main()
