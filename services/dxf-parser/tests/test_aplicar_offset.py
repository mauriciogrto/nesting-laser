import importlib.util
import json
import subprocess
import sys
import unittest
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / "aplicar_offset.py"
spec = importlib.util.spec_from_file_location("aplicar_offset", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ApplyOffsetTest(unittest.TestCase):
    def setUp(self):
        self.outer = [[0, 0], [100, 0], [100, 60], [0, 60]]
        self.hole = [[40, 25], [60, 25], [60, 35], [40, 35]]

    def bounds(self, points):
        return max(p[0] for p in points) - min(p[0] for p in points), max(p[1] for p in points) - min(p[1] for p in points)

    def test_outer_contracts_and_hole_expands_by_opposite_offset(self):
        result = module.apply_offset({"contornos": [self.outer, self.hole], "offset_mm": -0.1})
        self.assertEqual(len(result["contornos"]), 2)
        self.assertAlmostEqual(self.bounds(result["contornos"][0])[0], 99.8, places=3)
        self.assertAlmostEqual(self.bounds(result["contornos"][1])[0], 20.2, places=3)

    def test_positive_offset_expands_outer_and_contracts_hole(self):
        result = module.apply_offset({"contornos": [self.outer, self.hole], "offset_mm": 0.1})
        self.assertAlmostEqual(self.bounds(result["contornos"][0])[0], 100.2, places=3)
        self.assertAlmostEqual(self.bounds(result["contornos"][1])[0], 19.8, places=3)

    def test_collapsed_contour_returns_error_json(self):
        payload = {"contornos": [[[0, 0], [1, 0], [1, 1], [0, 1]]], "offset_mm": -1}
        process = subprocess.run([sys.executable, str(MODULE_PATH)], input=json.dumps(payload), capture_output=True, text=True)
        self.assertNotEqual(process.returncode, 0)
        self.assertIn("colapsou", json.loads(process.stdout)["erro"])

    def test_join_type_is_configurable(self):
        result = module.apply_offset({"contornos": [self.outer], "offset_mm": 1, "join_type": "round"})
        self.assertGreater(len(result["contornos"][0]), 4)


if __name__ == "__main__":
    unittest.main()
