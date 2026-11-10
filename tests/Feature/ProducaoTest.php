<?php

namespace Tests\Feature;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Enums\TipoMovimentacao;
use App\Models\Chapa;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCorte;
use App\Models\User;
use App\Services\EstoqueDoArranjo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProducaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_detail_separates_order_management_and_costs_without_the_cutting_measure_card(): void
    {
        $this->actingAs(User::factory()->create());
        $pedido = PedidoCorte::factory()->create(['chapa_id' => Chapa::factory()->create()->id]);

        $this->get(route('pedidos.show', $pedido))->assertOk()
            ->assertSee('id="painel-pedido"', false)
            ->assertSee('id="painel-gestao"', false)
            ->assertSee('id="painel-custos"', false)
            ->assertSee('Peças do pedido')->assertSee('Arquivos para download')
            ->assertSee('Salvar gestão')->assertSee('Custo total')
            ->assertDontSee('Medidas de corte usadas neste arranjo');
    }

    public function test_export_decrements_stock_creates_remnant_and_completion_updates_machine_cost_once(): void
    {
        $this->actingAs(User::factory()->create());
        $chapa = Chapa::factory()->create(['quantidade_disponivel' => 1, 'largura_mm' => 100, 'altura_mm' => 100, 'custo_unitario' => 100]);
        $chapa->espessura->update(['custo_hora_maquina' => 3600]);
        $pedido = PedidoCorte::factory()->create([
            'status' => StatusPedido::Pronto, 'chapa_id' => $chapa->id, 'tempo_corte_estimado_seg' => 60,
            'sobra_aproveitavel_json' => [[20, 0], [100, 0], [100, 100], [20, 100]],
        ]);
        DB::transaction(fn () => app(EstoqueDoArranjo::class)->baixarNaExportacao($pedido, $chapa));
        $this->assertNotNull($pedido->refresh()->estoque_baixado_em);
        $this->assertSame('20.00', $pedido->custo_material);
        $this->assertSame('60.00', $pedido->custo_maquina);
        $this->assertSame('80.00', $pedido->custo_total);
        $this->patch(route('pedidos.gestao', $pedido), ['data_prevista' => '2026-10-01',
            'tempo_corte_real_seg' => 45])->assertRedirect(route('pedidos.show', $pedido));
        $this->assertSame('45.00', $pedido->refresh()->custo_maquina);
        $this->assertSame('2026-10-01', $pedido->data_prevista->format('Y-m-d'));
        $this->get(route('pedidos.index', ['status' => StatusPedido::Pronto->value]))
            ->assertOk()->assertSee('R$ 65,00');
        $this->post(route('pedidos.iniciar', $pedido))->assertRedirect(route('pedidos.show', $pedido));
        $this->post(route('pedidos.concluir', $pedido), ['tempo_corte_real_seg' => 42])
            ->assertRedirect(route('pedidos.show', $pedido));
        $this->assertSame(StatusPedido::Concluido, $pedido->refresh()->status);
        $this->assertSame(42, $pedido->tempo_corte_real_seg);
        $this->assertSame('42.00', $pedido->custo_maquina);
        $this->assertSame('62.00', $pedido->custo_total);
        $this->assertSame(0, $chapa->refresh()->quantidade_disponivel);
        $this->assertSame(StatusChapa::Baixada, $chapa->status);
        $retalho = Chapa::where('chapa_pai_id', $chapa->id)->firstOrFail();
        $this->assertSame(OrigemChapa::Retalho, $retalho->origem);
        $this->assertSame('80.000', $retalho->largura_mm);
        $this->assertSame([[0, 0], [80, 0], [80, 100], [0, 100]], $retalho->contorno_json);
        $this->assertSame(1, MovimentacaoEstoque::where('tipo', TipoMovimentacao::Baixa)->count());
        $this->assertSame(1, MovimentacaoEstoque::where('tipo', TipoMovimentacao::GeracaoRetalho)->count());
        $this->post(route('pedidos.concluir', $pedido))->assertSessionHasErrors('pedido');
        $this->assertSame(2, MovimentacaoEstoque::count());
        $this->get(route('movimentacoes.index', ['chapa_id' => $chapa->id, 'tipo' => 'baixa']))
            ->assertOk()->assertSee('Chapa usada na exportação');
    }

    public function test_fractional_remnant_contour_uses_the_same_precision_as_its_dimensions(): void
    {
        $chapa = Chapa::factory()->create(['largura_mm' => 3000, 'altura_mm' => 1200]);
        $pedido = PedidoCorte::factory()->create([
            'status' => StatusPedido::Pronto, 'chapa_id' => $chapa->id,
            'sobra_aproveitavel_json' => [[1167.35788, 0], [3000, 0], [3000, 1200], [1167.35788, 1200]],
        ]);

        DB::transaction(fn () => app(EstoqueDoArranjo::class)->baixarNaExportacao($pedido, $chapa));
        $retalho = $pedido->refresh()->retalho;
        $this->assertSame('1832.642', $retalho->largura_mm);
        $this->assertSame([[0, 0], [1832.642, 0], [1832.642, 1200], [0, 1200]], $retalho->contorno_json);
    }
}
