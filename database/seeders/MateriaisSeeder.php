<?php

namespace Database\Seeders;

use App\Models\Material;
use Illuminate\Database\Seeder;

class MateriaisSeeder extends Seeder
{
    public function run(): void
    {
        // Valores de demonstracao; calibrar com a maquina, potencia e gas reais.
        $dados = [
            'Aço Carbono' => [[1, 4000, 0.25, 0.10], [2, 2600, 0.4, 0.14], [3, 1800, 0.65, 0.18]],
            'Aço Inox' => [[1, 3000, 0.35, 0.11], [2, 1900, 0.55, 0.15]],
            'Alumínio' => [[1, 3500, 0.35, 0.12], [2, 2200, 0.6, 0.17]],
        ];

        foreach ($dados as $nome => $espessuras) {
            $material = Material::firstOrCreate(['nome' => $nome]);
            foreach ($espessuras as [$valor, $velocidade, $perfuracao, $kerf]) {
                $espacamento = 10.0;
                $espessura = $material->espessuras()->firstOrCreate(
                    ['valor_mm' => $valor],
                    ['velocidade_corte_mm_min' => $velocidade, 'tempo_perfuracao_seg' => $perfuracao, 'kerf_mm' => $kerf,
                        'espacamento_minimo_mm' => $espacamento, 'lead_in_mm' => 2],
                );
                if ($espessura->kerf_mm === null) {
                    $espessura->update(['kerf_mm' => $kerf]);
                }
            }
        }
    }
}
