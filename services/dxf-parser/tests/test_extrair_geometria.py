import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import ezdxf

MODULE_PATH = Path(__file__).resolve().parents[1] / "extrair_geometria.py"
spec = importlib.util.spec_from_file_location("extrair_geometria", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ExtractGeometryTest(unittest.TestCase):
    def setUp(self):
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.path = Path(self.folder.name) / "amostra.dxf"
        self.doc = ezdxf.new(setup=True)
        self.doc.header["$INSUNITS"] = 4
        self.msp = self.doc.modelspace()

    def test_closed_polyline_and_circle(self):
        self.msp.add_lwpolyline([(0, 0), (20, 0), (20, 10), (0, 10)], close=True)
        self.msp.add_circle((10, 5), radius=2)
        self.doc.saveas(self.path)

        result = module.extract(self.path)

        self.assertEqual(len(result["contornos"]), 2)
        self.assertAlmostEqual(result["bounding_box"]["largura_mm"], 20)
        self.assertAlmostEqual(result["bounding_box"]["altura_mm"], 10)
        self.assertGreater(len(result["contornos"][1]), 8)

    def test_lines_are_stitched_into_polygon(self):
        for a, b in [((0, 0), (10, 0)), ((10, 10), (10, 0)), ((10, 10), (0, 10)), ((0, 0), (0, 10))]:
            self.msp.add_line(a, b)
        self.doc.saveas(self.path)
        result = module.extract(self.path)
        self.assertEqual(len(result["contornos"]), 1)
        self.assertEqual(len(result["contornos"][0]), 4)

    def test_bulged_polyline_is_flattened(self):
        self.msp.add_lwpolyline([(0, 0, 1), (10, 0, 0), (10, 10, 0), (0, 10, 0)], format="xyb", close=True)
        self.doc.saveas(self.path)
        points = module.extract(self.path)["contornos"][0]
        self.assertGreater(len(points), 4)

    def test_arc_and_line_form_closed_contour(self):
        self.msp.add_arc((5, 0), radius=5, start_angle=0, end_angle=180)
        self.msp.add_line((0, 0), (10, 0))
        self.doc.saveas(self.path)
        result = module.extract(self.path)
        self.assertEqual(len(result["contornos"]), 1)
        self.assertGreater(len(result["contornos"][0]), 8)

    def test_classic_polyline_is_supported(self):
        self.msp.add_polyline2d([(0, 0), (12, 0), (12, 8), (0, 8)], close=True)
        self.doc.saveas(self.path)
        self.assertEqual(module.extract(self.path)["bounding_box"]["largura_mm"], 12)

    def test_spline_and_line_form_closed_contour(self):
        self.msp.add_spline(fit_points=[(0, 0), (5, 5), (10, 0)], degree=2)
        self.msp.add_line((10, 0), (0, 0))
        self.doc.saveas(self.path)
        result = module.extract(self.path)
        self.assertGreater(len(result["contornos"][0]), 8)

    def test_valid_file_outputs_json_from_cli(self):
        self.msp.add_lwpolyline([(0, 0), (20, 0), (20, 10), (0, 10)], close=True)
        self.doc.saveas(self.path)
        proc = subprocess.run([sys.executable, str(MODULE_PATH), str(self.path)], capture_output=True, text=True)
        self.assertEqual(proc.returncode, 0, proc.stdout + proc.stderr)
        self.assertEqual(json.loads(proc.stdout)["bounding_box"]["largura_mm"], 20)

    def test_open_segment_returns_json_error_and_nonzero_status(self):
        self.msp.add_line((0, 0), (10, 0))
        self.doc.saveas(self.path)
        proc = subprocess.run([sys.executable, str(MODULE_PATH), str(self.path)], capture_output=True, text=True)
        self.assertNotEqual(proc.returncode, 0)
        self.assertIn("início (0.000, 0.000) mm", json.loads(proc.stdout)["erro"])
        self.assertIn("Feche ou remova linhas soltas", json.loads(proc.stdout)["erro"])

    def test_inch_units_convert_to_mm(self):
        self.doc.header["$INSUNITS"] = 1
        self.msp.add_lwpolyline([(0, 0), (1, 0), (1, 1), (0, 1)], close=True)
        self.doc.saveas(self.path)
        result = module.extract(self.path)
        self.assertAlmostEqual(result["bounding_box"]["largura_mm"], 25.4)


if __name__ == "__main__":
    unittest.main()
