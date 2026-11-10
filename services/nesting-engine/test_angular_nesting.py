import time
import unittest

from shapely.affinity import rotate, translate
from shapely.geometry import Polygon

from angular_nesting import solve
from compact_nesting import RegionPacker, area_score


class AngularNestingTest(unittest.TestCase):
    def payload(self, width=70, height=20):
        polygon = rotate(Polygon([(0,0),(60,0),(60,10),(0,10)]),23.75,origin=(0,0))
        x,y,_,_=polygon.bounds
        polygon = translate(polygon,-x,-y)
        return {'chapa':{'largura_mm':width,'altura_mm':height},
                'margem_borda_mm':2,'distancia_pecas_mm':2,
                'pecas':[{'id':'oblique','quantidade':1,'contornos':[list(polygon.exterior.coords)]}]}

    def test_fractional_rotation_fits_a_part_that_cardinal_angles_cannot_fit(self):
        payload = self.payload()
        result = solve(payload,1)
        self.assertEqual(len(result['pecas_posicionadas']),1)
        item = result['pecas_posicionadas'][0]
        self.assertNotAlmostEqual(item['rotacao_graus'] % 90,0,places=2)
        checker = RegionPacker(payload,time.monotonic()+2)
        placed = checker.import_layout(result['pecas_posicionadas'])
        self.assertEqual(len(placed),1)
        self.assertLessEqual(placed[0].y+placed[0].height,18.001)
        self.assertGreaterEqual(placed[0].x,1.999)

    def test_preserves_fractional_incumbent_and_original_geometry_on_roundtrip(self):
        payload = self.payload()
        checker = RegionPacker(payload,time.monotonic()+2)
        source = [dict(id='oblique',instancia=0,x=2,y=2,rotacao_graus=336.25)]
        placed = checker.import_layout(source)
        exported = checker.export(placed)
        self.assertEqual(exported[0]['rotacao_graus'],336.25)
        self.assertAlmostEqual(placed[0].height,10,places=5)
        again = checker.import_layout(exported)
        self.assertLess(again[0].polygon.symmetric_difference(placed[0].polygon).area,1e-7)
        result = solve(payload,.15,{'pecas_posicionadas':exported})
        after = checker.import_layout(result['pecas_posicionadas'])
        self.assertEqual(len(after),1)
        self.assertGreaterEqual(area_score(after,70,20),area_score(placed,70,20)-.01)

    def test_fractional_rotation_keeps_holes_and_clearance_to_neighbours(self):
        payload = {'chapa':{'largura_mm':200,'altura_mm':200},'margem_borda_mm':3,'distancia_pecas_mm':10,
            'pecas':[{'id':'frame','quantidade':2,'contornos':[
                [[0,0],[50,0],[50,30],[0,30]],[[10,10],[20,10],[20,20],[10,20]]]}]}
        checker = RegionPacker(payload,time.monotonic()+3)
        angle = checker.angle_index(0,27.35)
        a = checker.place(checker.parts[0],[],angles=[angle])
        b = checker.place(checker.parts[1],[a],angles=[checker.angle_index(0,112.8)])
        self.assertIsNotNone(b)
        self.assertEqual(len(b.polygon.interiors),1)
        self.assertGreaterEqual(a.polygon.distance(b.polygon),9.999)
        self.assertEqual(len(checker.import_layout(checker.export([a,b]))),2)


if __name__ == '__main__':
    unittest.main()
