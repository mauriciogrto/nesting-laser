<?php

namespace Database\Factories;

use App\Enums\TipoMovimentacao;
use App\Models\Chapa;
use App\Models\MovimentacaoEstoque;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MovimentacaoEstoque> */
class MovimentacaoEstoqueFactory extends Factory
{
    protected $model = MovimentacaoEstoque::class;

    public function definition(): array
    {
        return [
            'chapa_id' => Chapa::factory(),
            'tipo' => TipoMovimentacao::Entrada,
            'quantidade' => 1,
        ];
    }
}
