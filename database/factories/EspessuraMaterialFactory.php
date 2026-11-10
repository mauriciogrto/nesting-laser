<?php

namespace Database\Factories;

use App\Models\EspessuraMaterial;
use App\Models\Material;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EspessuraMaterial> */
class EspessuraMaterialFactory extends Factory
{
    protected $model = EspessuraMaterial::class;

    public function definition(): array
    {
        return [
            'material_id' => Material::factory(),
            'valor_mm' => 2,
            'velocidade_corte_mm_min' => 1800,
            'tempo_perfuracao_seg' => 0.7,
            'espacamento_minimo_mm' => 10,
            'lead_in_mm' => 2,
        ];
    }
}
