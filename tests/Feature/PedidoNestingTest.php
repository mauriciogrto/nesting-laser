<?php

namespace Tests\Feature;

use App\Enums\StatusPedido;
use App\Enums\StatusProcessamento;
use App\Models\Chapa;
use App\Models\Peca;
use App\Models\PedidoCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PedidoNestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirming_order_offsets_kerf_and_saves_ready_result(): void
    {
        $this->actingAs(User::factory()->create());
        [$pedido, $peca] = $this->draftWithPart();
        $sent = null;
        Http::fake(function (Request $request) use (&$sent, $peca) {
            $sent = $request->data();

            return Http::response([
                'pecas_posicionadas' => [[
                    'id' => $peca->id, 'instancia' => 0, 'x' => 0, 'y' => 0,
                    'rotacao_graus' => 0, 'contornos' => $sent['pecas'][0]['contornos'],
                ]],
                'pecas_nao_posicionadas' => [],
                'area_utilizada_pct' => 2,
                'sobra_contorno' => [[20, 0], [100, 0], [100, 100], [20, 100]],
            ]);
        });

        $this->post(route('pedidos.confirmar', $pedido))->assertRedirect();

        $pedido->refresh();
        $this->assertSame(StatusPedido::Pronto, $pedido->status);
        $this->assertNotNull($pedido->tempo_corte_estimado_seg);
        $this->assertSame(20.0, (float) $peca->refresh()->largura_bbox_mm);
        $this->assertSame(20, $peca->contorno_json[0][1][0]);
        $points = $sent['pecas'][0]['contornos'][0];
        $this->assertSame(10.0, $sent['distancia_pecas_mm']);
        $this->assertSame(10.0, $sent['margem_borda_mm']);
        $this->assertEquals(10.0, $pedido->resultado_nesting_json['espacamento_minimo_mm']);
        $width = max(array_column($points, 0)) - min(array_column($points, 0));
        $this->assertEqualsWithDelta(20.2, $width, 0.001);
        $this->assertSame($sent['pecas'][0]['contornos'], $pedido->resultado_nesting_json['pecas_posicionadas'][0]['contornos']);
        $this->getJson(route('pedidos.status', $pedido))->assertJsonPath('status', 'pronto');
    }

    public function test_engine_failure_marks_order_as_error(): void
    {
        $this->actingAs(User::factory()->create());
        [$pedido] = $this->draftWithPart();
        Http::fake(['*/nesting' => Http::response(['erro' => 'motor indisponível'], 500)]);

        $this->post(route('pedidos.confirmar', $pedido))->assertRedirect();

        $this->assertSame(StatusPedido::Erro, $pedido->refresh()->status);
        $this->assertNotEmpty($pedido->erro_mensagem);
    }

    public function test_order_cannot_use_unprocessed_part(): void
    {
        $this->actingAs(User::factory()->create());
        $chapa = Chapa::factory()->create();
        $peca = Peca::factory()->create();
        $this->post(route('pedidos.store'), ['chapa_id' => $chapa->id, 'pecas' => [$peca->id => 1]])
            ->assertSessionHasErrors('pecas');
    }

    private function draftWithPart(): array
    {
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100]);
        $chapa->espessura->update(['kerf_mm' => 0.2]);
        $peca = Peca::factory()->create([
            'status_processamento' => StatusProcessamento::Processado,
            'contorno_json' => [[[0, 0], [20, 0], [20, 10], [0, 10]]],
            'largura_bbox_mm' => 20, 'altura_bbox_mm' => 10,
        ]);
        $pedido = PedidoCorte::factory()->create(['chapa_id' => $chapa->id]);
        $pedido->pecas()->attach($peca->id, ['quantidade' => 1]);

        return [$pedido, $peca];
    }
}
