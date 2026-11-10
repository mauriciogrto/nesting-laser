<?php

namespace Tests\Feature;

use App\Enums\OrigemChapa;
use App\Enums\StatusPedido;
use App\Enums\TipoMovimentacao;
use App\Models\Chapa;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelatorioTest extends TestCase
{
    use RefreshDatabase;

    public function test_four_reports_and_csv_reflect_completed_work(): void
    {
        $this->actingAs(User::factory()->create());
        $chapa = Chapa::factory()->create();
        $pedido = PedidoCorte::factory()->create([
            'chapa_id' => $chapa->id, 'status' => StatusPedido::Concluido, 'concluido_em' => now(),
            'area_chapa_mm2' => 10000, 'area_pecas_mm2' => 2500,
            'tempo_corte_estimado_seg' => 100, 'tempo_corte_real_seg' => 125,
        ]);
        MovimentacaoEstoque::create(['chapa_id' => $chapa->id, 'pedido_corte_id' => $pedido->id, 'tipo' => TipoMovimentacao::Baixa, 'quantidade' => 1]);
        $retalho = Chapa::factory()->create(['material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id, 'origem' => OrigemChapa::Retalho, 'chapa_pai_id' => $chapa->id]);
        MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'pedido_corte_id' => $pedido->id, 'tipo' => TipoMovimentacao::GeracaoRetalho, 'quantidade' => 1]);

        $this->get(route('relatorios.aproveitamento'))->assertOk()->assertSee('Média do período: 25,0%');
        $this->get(route('relatorios.tempos'))->assertOk()->assertSee('125 s')->assertSee('25,0%');
        $this->get(route('relatorios.consumo'))->assertOk()->assertSee('Chapas baixadas')->assertSee($chapa->material->nome);
        $this->get(route('relatorios.retalhos'))->assertOk()->assertSee('Retalhos gerados')->assertSee($chapa->material->nome);
        $this->get(route('relatorios.aproveitamento.csv'))->assertOk()->assertDownload('aproveitamento.csv');
        $this->get(route('relatorios.aproveitamento', ['data_inicio' => now()->addDay()->toDateString(), 'data_fim' => now()->addDay()->toDateString()]))
            ->assertOk()->assertSee('Nenhum pedido no período.');
    }
}
