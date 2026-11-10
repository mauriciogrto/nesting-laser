<?php

namespace Database\Factories;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use App\Models\Chapa;
use App\Models\EspessuraMaterial;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Chapa> */
class ChapaFactory extends Factory
{
    protected $model = Chapa::class;

    public function definition(): array
    {
        return [
            'espessura_id' => EspessuraMaterial::factory(),
            'material_id' => fn (array $attributes) => EspessuraMaterial::findOrFail($attributes['espessura_id'])->material_id,
            'largura_mm' => 1000,
            'altura_mm' => 2000,
            'origem' => OrigemChapa::Nova,
            'quantidade_disponivel' => 3,
            'status' => StatusChapa::Disponivel,
        ];
    }
}
