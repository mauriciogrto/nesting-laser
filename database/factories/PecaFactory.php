<?php

namespace Database\Factories;

use App\Models\Peca;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Peca> */
class PecaFactory extends Factory
{
    protected $model = Peca::class;

    public function definition(): array
    {
        return [
            'nome' => 'Peça '.$this->faker->unique()->numerify('####'),
            'arquivo_dxf_original' => 'pecas/teste.dxf',
        ];
    }
}
