<?php

namespace Tests\Feature;

use App\Enums\StatusPedido;
use App\Models\Chapa;
use App\Models\PedidoCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_aggregates_stock_alerts_and_recent_completed_order(): void
    {
        $this->actingAs(User::factory()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 2]);
        $chapa->material->update(['estoque_minimo_alerta' => 3]);
        PedidoCorte::factory()->create(['chapa_id' => $chapa->id, 'status' => StatusPedido::Concluido, 'concluido_em' => now()]);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee($chapa->material->nome)
            ->assertSee('mínimo 3')
            ->assertSee('Produção concluída');
    }
}
