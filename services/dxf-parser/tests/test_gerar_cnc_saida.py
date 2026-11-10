import importlib.util
import json
import math
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / "gerar_cnc_saida.py"
sys.path.insert(0, str(MODULE_PATH.parent))
spec = importlib.util.spec_from_file_location("gerar_cnc_saida", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class GenerateCncTest(unittest.TestCase):
    def test_preview_and_output_preserve_fractional_rotation(self):
        payload = self.payload()
        payload['pecas_posicionadas'][0]['rotacao_graus'] = 23.75
        preview = module.plan(payload)['trajetos_cnc']
        outer = next(p for p in preview if p['tipo']=='externo')
        edges = [(b[0]-a[0],b[1]-a[1]) for a,b in zip(outer['corte'],outer['corte'][1:])]
        self.assertTrue(any(abs(math.degrees(math.atan2(y,x))-23.75)<.02 for x,y in edges))
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder)/'fractional.cnc'
            module.generate(payload,target)
            program = target.read_text()
            self.assertIn(module.command('G00',outer['entrada'][0]),program)
            self.assertEqual(program.count('M07'),2)

    def test_nested_piece_is_finished_before_its_host_cutout_is_released(self):
        payload = {'chapa': {'largura_mm': 150, 'altura_mm': 150}, 'lead_in_mm': 1,
            'pecas_posicionadas': [
                {'id': 'host', 'instancia': 0, 'x': 5, 'y': 5, 'rotacao_graus': 0,
                 'contornos': [[[0,0],[120,0],[120,120],[0,120]], [[20,20],[100,20],[100,100],[20,100]]]},
                {'id': 'nested', 'instancia': 1, 'x': 40, 'y': 40, 'rotacao_graus': 0,
                 'contornos': [[[0,0],[40,0],[40,40],[0,40]], [[10,10],[30,10],[30,30],[10,30]]]}]}
        paths = module.plan(payload)['trajetos_cnc']
        self.assertEqual([(p['id'],p['tipo']) for p in paths],
                         [('nested','furo'),('nested','externo'),('host','furo'),('host','externo')])

    def payload(self):
        square = [[0, 0], [20, 0], [20, 20], [0, 20]]
        hole = [[6, 6], [14, 6], [14, 14], [6, 14]]
        return {
            "chapa": {"largura_mm": 100, "altura_mm": 80},
            "feed_mm_min": 400,
            "pecas_posicionadas": [{"id": "peca", "instancia": 0,
                                    "x": 10, "y": 10, "rotacao_graus": 0,
                                    "contornos": [square, hole]}],
        }

    def test_preview_and_cnc_use_same_paths_with_holes_first(self):
        payload = self.payload()
        preview = module.plan(payload)["trajetos_cnc"]
        self.assertEqual([path["tipo"] for path in preview], ["furo", "externo"])
        for path in preview:
            self.assertEqual(path["corte"][0], path["corte"][-1])
            self.assertEqual(path["saida"][-1], path["entrada"][0])

        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / "teste.cnc"
            result = module.generate(payload, output)
            lines = output.read_text(encoding="ascii").splitlines()
            self.assertEqual(result["trajetos"], 2)
            self.assertEqual(lines[:5], ["%", "G90", "G71", "G40", "(Nesting Laser)"])
            self.assertEqual(lines[-1], "M02")
            self.assertEqual(lines.count("M07"), 2)
            self.assertEqual(lines.count("M08"), 2)
            self.assertEqual(sum(line.startswith("G00 ") for line in lines), 2)
            self.assertTrue(all("F400" in line for line in lines if line.startswith("G01 ")))
            self.assertEqual(lines[5], module.command("G00", preview[0]["entrada"][0]))

    def test_arcs_use_relative_centers_and_match_preview_within_tolerance(self):
        circle = [[20 + 10 * math.cos(i * 2 * math.pi / 64),
                   20 + 10 * math.sin(i * 2 * math.pi / 64)] for i in range(64)]
        payload = {"chapa": {"largura_mm": 80, "altura_mm": 80},
                   "feed_mm_min": 400,
                   "pecas_posicionadas": [{"id": 1, "instancia": 0, "x": 10, "y": 10,
                                            "rotacao_graus": 0, "contornos": [circle]}]}
        path = module.plan(payload)["trajetos_cnc"][0]
        arcs = [move for move in path["movimentos"] if move["code"] in ("G02", "G03")]
        self.assertGreaterEqual(len(arcs), 2)
        self.assertTrue(all("i" in move and "j" in move for move in arcs))
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / "circle.cnc"
            module.generate(payload, output)
            program = output.read_text(encoding="ascii")
            self.assertIn("G03 X", program)
            self.assertIn(" I", program)
            self.assertIn(" J", program)

    def test_no_safe_lead_blocks_machine_output(self):
        payload = self.payload()
        payload["chapa"] = {"largura_mm": 20, "altura_mm": 20}
        payload["pecas_posicionadas"][0]["x"] = 0
        payload["pecas_posicionadas"][0]["y"] = 0
        with self.assertRaisesRegex(ValueError, "entrada de corte segura"):
            module.plan(payload)

    def test_lead_in_stays_inside_safe_sheet_margin(self):
        payload = self.payload()
        payload["margem_borda_mm"] = 5
        for path in module.plan(payload)["trajetos_cnc"]:
            x, y = path["entrada"][0]
            self.assertGreaterEqual(x, 5)
            self.assertGreaterEqual(y, 5)
            self.assertLessEqual(x, 95)
            self.assertLessEqual(y, 75)

    def test_cli_preview_matches_generated_program(self):
        payload = self.payload()
        process = subprocess.run([sys.executable, str(MODULE_PATH), "--preview"],
                                 input=json.dumps(payload), text=True, capture_output=True)
        self.assertEqual(process.returncode, 0, process.stdout + process.stderr)
        self.assertEqual(len(json.loads(process.stdout)["trajetos_cnc"]), 2)

    def test_pierce_starts_at_configured_distance_from_a_corner(self):
        payload = self.payload()
        payload["lead_in_mm"] = 3
        for path in module.plan(payload)["trajetos_cnc"]:
            self.assertAlmostEqual(math.dist(*path["entrada"]), 3, places=3)
            ring = payload["pecas_posicionadas"][0]["contornos"][0 if path["tipo"] == "externo" else 1]
            vertices = [[point[0] + 10, point[1] + 10] for point in ring]
            self.assertIn(path["entrada"][1], vertices)
            self.assertNotIn(path["entrada"][0], vertices)

    def test_all_holes_precede_exteriors_and_large_release_is_last(self):
        payload = self.payload()
        payload["pecas_posicionadas"].append({"id": "pequena", "instancia": 1, "x": 55, "y": 10,
                                             "rotacao_graus": 0,
                                             "contornos": [[[0, 0], [10, 0], [10, 10], [0, 10]],
                                                          [[3, 3], [7, 3], [7, 7], [3, 7]]]})
        paths = module.plan(payload)["trajetos_cnc"]
        self.assertEqual([path["tipo"] for path in paths], ["furo", "furo", "externo", "externo"])
        self.assertEqual([path["id"] for path in paths[2:]], ["pequena", "peca"])

    def test_tabs_leave_measured_uncut_gap_in_preview_and_cnc(self):
        payload = self.payload()
        payload["usar_tabs"] = True
        payload["tamanho_tab_mm"] = 2
        paths = module.plan(payload)["trajetos_cnc"]
        hole, outer = paths
        self.assertEqual(hole["corte"][0], hole["corte"][-1])
        self.assertNotEqual(outer["corte"][0], outer["corte"][-1])
        self.assertAlmostEqual(sum(math.dist(a, b) for a, b in zip(outer["tab"], outer["tab"][1:])), 2, places=3)
        with tempfile.TemporaryDirectory() as folder:
            output = Path(folder) / "tabs.cnc"
            module.generate(payload, output)
            program = output.read_text(encoding="ascii")
            self.assertEqual(program.count("M07"), 2)
            self.assertEqual(program.count("M08"), 2)
            self.assertLess(program.rfind("G01"), program.rfind("M08"))


if __name__ == "__main__":
    unittest.main()
