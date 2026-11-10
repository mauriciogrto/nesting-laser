<?php

namespace Tests\Unit;

use App\Models\EspessuraMaterial;
use App\Services\CalculadoraTempoCorte;
use Tests\TestCase;

class CalculadoraTempoCorteTest extends TestCase
{
    public function test_nested_part_precedes_release_of_its_host_cutout(): void
    {
        $parts = [
            ['x' => 5, 'y' => 5, 'rotacao_graus' => 0, 'contornos' => [
                [[0, 0], [120, 0], [120, 120], [0, 120]],
                [[20, 20], [100, 20], [100, 100], [20, 100]],
            ]],
            ['x' => 40, 'y' => 40, 'rotacao_graus' => 0, 'contornos' => [
                [[0, 0], [40, 0], [40, 40], [0, 40]],
                [[10, 10], [30, 10], [30, 30], [10, 30]],
            ]],
        ];
        $order = (new CalculadoraTempoCorte)->ordemCorte($parts);
        $this->assertSame(['furo', 'externo', 'furo', 'externo'], array_column($order, 'tipo'));
        $this->assertSame([1600.0, 1600.0, 14400.0, 14400.0], array_column($order, 'area_externa_mm2'));
    }

    public function test_holes_precede_all_exteriors_and_tabs_reduce_cut_length(): void
    {
        config(['nesting.deslocamento_mm_min' => 1000000]);
        $calculator = new CalculadoraTempoCorte;
        $thickness = new EspessuraMaterial(['velocidade_corte_mm_min' => 60,
            'tempo_perfuracao_seg' => 0, 'lead_in_mm' => 2]);
        $parts = [
            ['x' => 0, 'y' => 0, 'rotacao_graus' => 0, 'contornos' => [
                [[0, 0], [20, 0], [20, 20], [0, 20]],
                [[5, 5], [10, 5], [10, 10], [5, 10]],
            ]],
            ['x' => 30, 'y' => 0, 'rotacao_graus' => 0, 'contornos' => [
                [[0, 0], [10, 0], [10, 10], [0, 10]],
            ]],
        ];
        $order = $calculator->ordemCorte($parts);
        $this->assertSame(['furo', 'externo', 'externo'], array_column($order, 'tipo'));
        $this->assertSame([100.0, 400.0], array_column(array_slice($order, 1), 'area_externa_mm2'));
        $whole = $calculator->calcular($parts, $thickness);
        $tabbed = $calculator->calcular($parts, $thickness,
            ['usar_tabs' => true, 'tamanho_tab_mm' => 1]);
        $this->assertSame(2, $whole - $tabbed);
    }
}
