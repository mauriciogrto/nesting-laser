<?php

namespace Database\Factories;

use App\Enums\StatusPedido;
use App\Models\PedidoCorte;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PedidoCorte> */
class PedidoCorteFactory extends Factory
{
    protected $model = PedidoCorte::class;

    public function definition(): array
    {
        return ['status' => StatusPedido::Rascunho];
    }
}
