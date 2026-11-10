<?php

namespace Tests\Feature;

use App\Enums\StatusPedido;
use App\Enums\TipoMovimentacao;
use App\Models\Chapa;
use App\Models\HistoricoAcao;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcessoAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_access_and_inactive_login_are_restricted(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->post(route('usuarios.store'), [
            'name' => 'Operador', 'email' => 'operador@example.test', 'role' => 'operador', 'password' => 'senha-forte-1234',
        ])->assertRedirect(route('usuarios.index'));
        $operator = User::where('email', 'operador@example.test')->firstOrFail();
        $this->actingAs($operator);
        $this->get(route('pedidos.index'))->assertOk();
        $this->get(route('chapas.index'))->assertOk();
        $this->get(route('materiais.index'))->assertOk();
        $this->get(route('materiais.create'))->assertForbidden();
        $this->get(route('relatorios.index'))->assertForbidden();
        $this->get(route('usuarios.index'))->assertForbidden();
        $this->get(route('historico.index'))->assertForbidden();

        $this->actingAs($admin)->put(route('usuarios.update', $operator), [
            'name' => 'Operador', 'email' => 'operador@example.test', 'role' => 'operador', 'active' => 0,
        ])->assertRedirect(route('usuarios.index'));
        $this->actingAs($operator->refresh())->get(route('pedidos.index'))->assertForbidden();
        auth()->logout();
        $this->post(route('login'), ['email' => 'operador@example.test', 'password' => 'senha-forte-1234'])
            ->assertSessionHasErrors('email');
        $this->actingAs($admin)->put(route('usuarios.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'operador', 'active' => 1,
        ])->assertSessionHasErrors('role');
    }

    public function test_observers_record_order_transitions_arrangement_and_stock_events(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $chapa = Chapa::factory()->create();
        $pedido = PedidoCorte::factory()->create(['chapa_id' => $chapa->id]);
        $pedido->update(['status' => StatusPedido::Pronto]);
        $pedido->update(['status' => StatusPedido::EmProducao, 'resultado_nesting_json' => ['pecas_posicionadas' => [['id' => 1]]]]);
        MovimentacaoEstoque::create(['chapa_id' => $chapa->id, 'pedido_corte_id' => $pedido->id, 'tipo' => TipoMovimentacao::Baixa, 'quantidade' => 1]);
        $this->assertSame(2, HistoricoAcao::where('acao', 'status_pedido')->count());
        $this->assertSame(1, HistoricoAcao::where('acao', 'confirmacao_arranjo')->count());
        $this->assertSame(1, HistoricoAcao::where('acao', 'baixa_estoque')->count());
        $this->assertSame($admin->id, HistoricoAcao::firstOrFail()->user_id);
        $this->get(route('historico.index', ['acao' => 'baixa_estoque']))->assertOk()->assertSee('baixa estoque');
    }
}
