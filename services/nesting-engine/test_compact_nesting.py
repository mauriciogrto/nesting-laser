import time
import unittest

from shapely.geometry import Polygon

from compact_nesting import RegionPacker, area_score, solve


def rectangle(width, height):
    return [[0, 0], [width, 0], [width, height], [0, height]]


class CompactNestingTest(unittest.TestCase):
    def payload(self, pieces, width=100, height=100, gap=2):
        return {'chapa': {'largura_mm': width, 'altura_mm': height},
                'distancia_pecas_mm': gap, 'margem_borda_mm': 2, 'pecas': pieces}

    def assert_safe(self, packer, placed):
        self.assertEqual(len({p.instance for p in placed}), len(placed))
        for i, p in enumerate(placed):
            self.assertGreaterEqual(p.x, packer.margin - .001)
            self.assertGreaterEqual(p.y, packer.margin - .001)
            self.assertLessEqual(p.x + p.width, packer.width - packer.margin + .001)
            self.assertLessEqual(p.y + p.height, packer.height - packer.margin + .001)
            for q in placed[i+1:]:
                self.assertLess(p.polygon.intersection(q.polygon).area, .00001)
                self.assertGreaterEqual(p.polygon.distance(q.polygon), packer.gap - .001)

    def test_places_two_small_parts_inside_one_cutout(self):
        payload = self.payload([
            {'id': 'frame', 'quantidade': 1, 'contornos': [rectangle(90, 90),
                [[20, 20], [70, 20], [70, 70], [20, 70]]]},
            {'id': 'small', 'quantidade': 2, 'contornos': [rectangle(20, 18)]}])
        packer = RegionPacker(payload, time.monotonic() + 3)
        placed = packer.pack(packer.parts)
        self.assertEqual(len(placed), 3)
        hole = Polygon(placed[0].polygon.interiors[0])
        self.assertTrue(all(hole.contains(p.polygon) for p in placed[1:]))
        self.assert_safe(packer, placed)

    def test_concave_parts_interlock_despite_overlapping_bounding_boxes(self):
        shape = [[0, 0], [45, 0], [45, 12], [12, 12], [12, 45], [0, 45]]
        payload = self.payload([{'id': 'L', 'quantidade': 2, 'contornos': [shape]}], 70, 100)
        packer = RegionPacker(payload, time.monotonic() + 3)
        placed = packer.pack(packer.parts)
        self.assertEqual(len(placed), 2)
        self.assertLess(max(p.y+p.height for p in placed), 65)
        self.assertGreater(placed[0].polygon.envelope.intersection(placed[1].polygon.envelope).area, 100)
        self.assert_safe(packer, placed)

    def test_rounded_cutout_keeps_two_near_exact_fit_fillers_inside(self):
        # The real batch has a ~280.5 mm opening. Extra coarse NFP padding
        # previously excluded this pair despite its physically valid gap.
        hole = Polygon([[10, 10], [290.5, 10], [290.5, 220.5], [10, 220.5]]).buffer(-2).buffer(2)
        payload = self.payload([
            {'id': 'frame', 'quantidade': 1, 'contornos': [rectangle(310, 240), list(hole.exterior.coords)]},
            {'id': 'a', 'quantidade': 1, 'contornos': [rectangle(176.9, 144)]},
            {'id': 'b', 'quantidade': 1, 'contornos': [rectangle(97, 172.9)]}], 500, 600, gap=2.1)
        packer = RegionPacker(payload, time.monotonic()+5)
        placed = packer.pack(packer.parts)
        self.assertEqual(len(placed), 3)
        opening = Polygon(placed[0].polygon.interiors[0])
        self.assertTrue(all(opening.contains(p.polygon) for p in placed[1:]))
        self.assert_safe(packer, placed)

    def test_repacking_improves_a_valid_scattered_baseline(self):
        payload = self.payload([{'id': 1, 'quantidade': 4, 'contornos': [rectangle(20, 20)]}])
        baseline = {'pecas_posicionadas': [dict(id=1, instancia=i, x=x, y=y, rotacao_graus=0)
            for i, (x, y) in enumerate([(2, 2), (70, 2), (2, 70), (70, 70)])]}
        packer = RegionPacker(payload, time.monotonic()+4)
        before = packer.import_layout(baseline['pecas_posicionadas'])
        result = solve(payload, 1, baseline)
        after = packer.import_layout(result['pecas_posicionadas'])
        self.assertEqual(len(after), 4)
        self.assertGreater(area_score(after,100,100), area_score(before,100,100))
        self.assert_safe(packer, after)

    def test_rejects_unsafe_or_mismatched_baseline(self):
        payload = self.payload([{'id': 1, 'quantidade': 2, 'contornos': [rectangle(20,20)]}])
        packer = RegionPacker(payload, time.monotonic()+2)
        positions = [dict(id=1, instancia=i, x=2+i*21, y=2, rotacao_graus=0) for i in range(2)]
        self.assertEqual(packer.import_layout(positions), [])
        positions[1].update(x=30, instancia=0)
        self.assertEqual(packer.import_layout(positions), [])

    def test_moves_external_filler_into_a_hole_without_moving_the_host(self):
        payload = self.payload([
            {'id': 'frame', 'quantidade': 1, 'contornos': [rectangle(80, 80),
                [[20, 20], [60, 20], [60, 60], [20, 60]]]},
            {'id': 'small', 'quantidade': 1, 'contornos': [rectangle(20, 20)]}], 160, 160)
        packer = RegionPacker(payload, time.monotonic()+3)
        before = packer.import_layout([
            dict(id='frame', instancia=0, x=2, y=2, rotacao_graus=0),
            dict(id='small', instancia=1, x=110, y=110, rotacao_graus=0)])
        after = packer.fill_cutouts(before)
        host = next(p for p in after if p.id == 'frame')
        filler = next(p for p in after if p.id == 'small')
        self.assertEqual((host.x, host.y), (2, 2))
        self.assertTrue(Polygon(host.polygon.interiors[0]).contains(filler.polygon))
        self.assertGreater(area_score(after,160,160),area_score(before,160,160))
        self.assert_safe(packer, after)

    def test_larger_gap_is_enforced_without_changing_quantities(self):
        payload = self.payload([{'id': 1, 'quantidade': 5, 'contornos': [rectangle(16, 12)]}], gap=10)
        packer = RegionPacker(payload, time.monotonic()+3)
        placed = packer.pack(packer.parts)
        self.assertEqual(len(placed), 5)
        self.assert_safe(packer, placed)


if __name__ == '__main__':
    unittest.main()
