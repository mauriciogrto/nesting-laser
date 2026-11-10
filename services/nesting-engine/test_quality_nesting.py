import unittest
import time

from shapely.affinity import rotate, translate
from shapely.geometry import Polygon

from quality_nesting import complete_repeated_columns, refine_relocation, relocate_rightmost, relocate_topmost, solve


class QualityNestingTest(unittest.TestCase):
    def test_places_a_small_part_inside_a_large_cutout_with_required_clearance(self):
        outer = [[0, 0], [90, 0], [90, 90], [0, 90]]
        hole = [[25, 25], [65, 25], [65, 65], [25, 65]]
        small = [[0, 0], [15, 0], [15, 15], [0, 15]]
        payload = {"chapa": {"largura_mm": 100, "altura_mm": 100},
                   "margem_borda_mm": 5, "distancia_pecas_mm": 5,
                   "pecas": [{"id": "moldura", "quantidade": 1, "contornos": [outer, hole]},
                             {"id": "pequena", "quantidade": 1, "contornos": [small]}]}
        result = solve(payload, 3)
        self.assertEqual(len(result["pecas_posicionadas"]), 2)
        placed = {item["id"]: item for item in result["pecas_posicionadas"]}
        frame = placed["moldura"]
        piece = placed["pequena"]
        frame_world = translate(Polygon(outer, [hole]), frame["x"], frame["y"])
        piece_world = translate(Polygon(small), piece["x"], piece["y"])
        self.assertTrue(Polygon(frame_world.interiors[0]).contains(piece_world))
        self.assertGreaterEqual(frame_world.distance(piece_world), 4.999)

    def test_hole_can_precede_outer_contour_in_dxf_entity_order(self):
        outer = [[0, 0], [30, 0], [30, 20], [0, 20]]
        hole = [[10, 5], [20, 5], [20, 15], [10, 15]]
        payload = {"chapa": {"largura_mm": 70, "altura_mm": 30},
                   "margem_borda_mm": 1, "distancia_pecas_mm": 2,
                   "pecas": [{"id": 1, "quantidade": 2,
                              "contornos": [hole, outer]}]}
        result = solve(payload, 3)
        self.assertEqual(len(result["pecas_posicionadas"]), 2)
        self.assertEqual(len(result["pecas_posicionadas"][0]["contornos"]), 2)

    def test_repeated_columns_complete_a_batch_after_edge_strip(self):
        def shape(index, width, height):
            polygon = Polygon([(0, 0), (width, 0), (width, height), (0, height)])
            variants = []
            for angle in (0, 90, 180, 270):
                rotated = rotate(polygon, angle, origin=(0, 0))
                x0, y0, _, _ = rotated.bounds
                normalized = translate(rotated, -x0, -y0)
                variants.append((normalized, list(normalized.exterior.coords[:-1]),
                                 normalized.bounds[2], normalized.bounds[3]))
            return ([list(polygon.exterior.coords[:-1])], polygon.area, variants)

        shapes = [shape(0, 300, 100), shape(1, 100, 300)]
        skinny = shapes[1][2][1][0]
        prefix = [(1, 2, 4, 1, 3, 500, translate(skinny, 3, 500), 300, 100),
                  (1, 2, 5, 1, 3, 850, translate(skinny, 3, 850), 300, 100)]
        parts = [(0, 1, i) for i in range(4)] + [(1, 2, i + 4) for i in range(4)]
        result = complete_repeated_columns(prefix, shapes, parts, 0, parts[-2:],
                                           1500, 3000, 3, 3, time.monotonic() + 3)
        self.assertEqual(len(result), len(parts))
        self.assertEqual(len({item[2] for item in result}), len(parts))

    def test_relocates_a_protruding_part_into_a_pocket_requiring_both_axes(self):
        def fixture(width, height, shape_index, x, y):
            polygon = Polygon([(0, 0), (width, 0), (width, height), (0, height)])
            world = translate(polygon, x, y)
            variants = [(polygon, list(polygon.exterior.coords[:-1]), width, height)] * 4
            shape = ([list(polygon.exterior.coords[:-1])], polygon.area, variants)
            item = (shape_index, shape_index, shape_index, 0, x, y, world, width, height)
            return shape, item

        fixtures = [fixture(20, 10, 0, 1, 1), fixture(10, 30, 1, 23, 1),
                    fixture(10, 10, 2, 40, 25)]
        shapes = [entry[0] for entry in fixtures]
        placed = [entry[1] for entry in fixtures]
        result = relocate_rightmost(placed, shapes, lambda *_: [], 60, 40, 1, 2,
                                    time.monotonic() + 3)

        self.assertLessEqual(max(item[4] + item[7] for item in result), 33.001)
        self.assertEqual(result[2][4], 1)
        self.assertGreaterEqual(result[2][5], 13)
        self.assertGreaterEqual(result[2][6].distance(result[0][6]), 1.999)

    def test_relocates_tied_topmost_parts_before_the_upper_envelope_can_shrink(self):
        rectangles = [(20, 20, 1, 1), (10, 10, 1, 35), (10, 10, 13, 35)]
        shapes = []
        placed = []
        for index, (width, height, x, y) in enumerate(rectangles):
            polygon = Polygon([(0, 0), (width, 0), (width, height), (0, height)])
            variants = [(polygon, list(polygon.exterior.coords[:-1]), width, height)] * 4
            shapes.append(([list(polygon.exterior.coords[:-1])], polygon.area, variants))
            placed.append((index, index, index, 0, x, y,
                           translate(polygon, x, y), width, height))
        result = relocate_topmost(placed, shapes, lambda *_: [], 50, 50, 1, 2,
                                  time.monotonic() + 3)
        self.assertLess(max(part[5] + part[8] for part in result), 45)
        for index, first in enumerate(result):
            for second in result[index + 1:]:
                self.assertGreaterEqual(first[6].distance(second[6]), 2)

    def test_refines_a_relocated_part_toward_its_neighbour(self):
        rectangle = Polygon([(0, 0), (10, 0), (10, 10), (0, 10)])
        stationary = (0, 0, 0, 0, 1, 1, translate(rectangle, 1, 1), 10, 10)
        moving = (1, 1, 1, 0, 31, 25, translate(rectangle, 31, 25), 10, 10)
        result = refine_relocation([stationary, moving], 1, 100, 50, 1, 2,
                                   time.monotonic() + 3)

        self.assertLess(result[1][4] + result[1][7], 41)
        self.assertGreaterEqual(result[1][6].distance(stationary[6]), 1.999)

    def test_kerf_clearance_uses_contours_and_keeps_safe_border(self):
        outline = [[0, 0], [20, 0], [20, 5], [5, 5], [5, 20], [0, 20]]
        payload = {"chapa": {"largura_mm": 45, "altura_mm": 45},
                   "margem_borda_mm": 2, "distancia_pecas_mm": 1,
                   "pecas": [{"id": 1, "quantidade": 2, "contorno": outline}]}
        result = solve(payload, 3)
        self.assertEqual(len(result["pecas_posicionadas"]), 2)
        worlds = []
        for item in result["pecas_posicionadas"]:
            polygon = rotate(Polygon(item["contornos"][0]), item["rotacao_graus"], origin=(0, 0))
            left, bottom, _, _ = polygon.bounds
            polygon = translate(polygon, item["x"] - left, item["y"] - bottom)
            self.assertGreaterEqual(polygon.bounds[0], 2 - 1e-6)
            self.assertGreaterEqual(polygon.bounds[1], 2 - 1e-6)
            self.assertLessEqual(polygon.bounds[2], 43 + 1e-6)
            self.assertLessEqual(polygon.bounds[3], 43 + 1e-6)
            worlds.append(polygon)
        self.assertGreaterEqual(worlds[0].distance(worlds[1]), 0.999)

    def test_interlocks_detailed_concave_parts_that_bounding_boxes_cannot_fit(self):
        corners = [[0, 0], [60, 0], [60, 20], [20, 20], [20, 60], [0, 60]]
        outline = [[a[0] + (b[0] - a[0]) * step / 10,
                    a[1] + (b[1] - a[1]) * step / 10]
                   for a, b in zip(corners, corners[1:] + corners[:1])
                   for step in range(10)]
        payload = {"chapa": {"largura_mm": 80, "altura_mm": 80},
                   "pecas": [{"id": 1, "quantidade": 2, "contorno": outline}]}
        result = solve(payload, 3)
        self.assertEqual(len(result["pecas_posicionadas"]), 2)
        worlds = []
        for item in result["pecas_posicionadas"]:
            polygon = rotate(Polygon(item["contornos"][0]), item["rotacao_graus"], origin=(0, 0))
            left, bottom, _, _ = polygon.bounds
            polygon = translate(polygon, item["x"] - left, item["y"] - bottom)
            self.assertGreaterEqual(polygon.bounds[0], -1e-6)
            self.assertGreaterEqual(polygon.bounds[1], -1e-6)
            self.assertLessEqual(polygon.bounds[2], 80 + 1e-6)
            self.assertLessEqual(polygon.bounds[3], 80 + 1e-6)
            worlds.append(polygon)
        self.assertLess(worlds[0].intersection(worlds[1]).area, 1e-5)


if __name__ == "__main__":
    unittest.main()
