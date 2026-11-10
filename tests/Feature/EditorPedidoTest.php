<?php

namespace Tests\Feature;

use App\Enums\StatusPedido;
use App\Models\Chapa;
use App\Models\PedidoChapa;
use App\Models\PedidoCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorPedidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_editor_serves_real_geometry_and_confirms_adjusted_layout(): void
    {
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $pedido = $this->readyOrder();
        $this->get(route('pedidos.editor', $pedido))->assertOk();
        $this->getJson(route('pedidos.editor.data', $pedido))
            ->assertJsonPath('pecas_posicionadas.0.contornos.0.1.0', 20)
            ->assertJsonPath('distancia_pecas_mm', 10)
            ->assertJsonPath('margem_borda_mm', 10)
            ->assertJsonCount(1, 'trajetos_cnc');

        $position = ['instancia' => 0, 'id' => 7, 'x' => 10, 'y' => 10, 'rotacao_graus' => 90];
        $this->postJson(route('pedidos.editor.recalcular', $pedido), ['posicoes' => [$position]])
            ->assertOk()->assertJsonStructure(['tempo_corte_estimado_seg']);
        $this->assertSame(StatusPedido::Pronto, $pedido->refresh()->status);

        $this->postJson(route('pedidos.editor.confirmar', $pedido), ['posicoes' => [$position]])
            ->assertOk()->assertJsonPath('status', 'em_producao');
        $pedido->refresh();
        $this->assertSame(StatusPedido::EmProducao, $pedido->status);
        $this->assertSame(10, $pedido->resultado_nesting_json['pecas_posicionadas'][0]['x']);
        $this->assertSame(90, $pedido->resultado_nesting_json['pecas_posicionadas'][0]['rotacao_graus']);
        $this->assertEquals(10, $pedido->resultado_nesting_json['espacamento_minimo_mm']);
        $this->assertSame([[[0, 0], [20, 0], [20, 10], [0, 10]]], $pedido->resultado_nesting_json['pecas_posicionadas'][0]['contornos']);
        $this->assertNotNull($pedido->cnc_saida);
        Storage::disk('local')->assertExists($pedido->cnc_saida);
        $this->get(route('pedidos.cnc', $pedido))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=pedido-'.$pedido->id.'.cnc');
    }

    public function test_editor_rejects_piece_outside_the_sheet(): void
    {
        $this->actingAs(User::factory()->create());
        $pedido = $this->readyOrder();
        $this->postJson(route('pedidos.editor.confirmar', $pedido), ['posicoes' => [[
            'instancia' => 0, 'id' => 7, 'x' => 95, 'y' => 0, 'rotacao_graus' => 0,
        ]]])->assertUnprocessable()->assertJsonValidationErrors('posicoes');
        $this->assertSame(StatusPedido::Pronto, $pedido->refresh()->status);
    }

    public function test_second_sheet_is_edited_inside_the_same_order_and_gets_its_own_cnc_and_pdf(): void
    {
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $pedido = $this->readyOrder();
        Storage::disk('local')->put('saidas/primeira.cnc', 'G90');
        $pedido->update(['cnc_saida' => 'saidas/primeira.cnc', 'estoque_baixado_em' => now()]);
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100,
            'material_id' => $pedido->chapa->material_id, 'espessura_id' => $pedido->chapa->espessura_id]);
        $plano = PedidoChapa::create(['pedido_corte_id' => $pedido->id, 'numero' => 2,
            'chapa_id' => $chapa->id, 'resultado_nesting_json' => $pedido->resultado_nesting_json,
            'tempo_corte_estimado_seg' => 3, 'area_chapa_mm2' => 10000,
            'area_pecas_mm2' => 200, 'estoque_baixado_em' => now()]);
        Http::fake(['*/avaliar' => Http::response(['area_utilizada_pct' => 2,
            'sobra_contorno' => [[40, 0], [100, 0], [100, 100], [40, 100]]])]);
        $this->get(route('pedidos.editor', [$pedido, 'chapa' => 2]))->assertOk()->assertSee('Chapa 2');
        $this->getJson(route('pedidos.editor.data', [$pedido, 'chapa' => 2]))
            ->assertOk()->assertJsonPath('chapa.largura_mm', 100);
        $position = ['instancia' => 0, 'id' => 7, 'x' => 10, 'y' => 5, 'rotacao_graus' => 0];
        $this->postJson(route('pedidos.editor.confirmar', [$pedido, 'chapa' => 2]),
            ['posicoes' => [$position]])->assertOk()->assertJsonPath('status', 'pronto');
        $this->assertSame(10, $plano->refresh()->resultado_nesting_json['pecas_posicionadas'][0]['x']);
        $this->assertSame(0, $pedido->refresh()->resultado_nesting_json['pecas_posicionadas'][0]['x']);
        $this->assertNotNull($plano->cnc_saida);
        $this->get(route('pedidos.chapas.cnc', [$pedido, 2]))->assertOk();
        $this->get(route('pedidos.chapas.pdf', [$pedido, 2]))->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $first = $this->postJson(route('pedidos.editor.confirmar', $pedido),
            ['posicoes' => [$position]])->assertOk()->assertJsonPath('status', 'pronto');
        $this->assertSame($first->json('tempo_corte_estimado_seg') + $plano->refresh()->tempo_corte_estimado_seg,
            $pedido->refresh()->tempo_corte_estimado_seg);
    }

    public function test_automatic_reorganization_previews_positions_without_changing_the_order(): void
    {
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $this->actingAs(User::factory()->create());
        $pedido = $this->readyOrder();
        Http::fake([
            '*/nesting' => Http::response([
                'pecas_posicionadas' => [[
                    'id' => 7, 'instancia' => 0, 'x' => 10, 'y' => 5,
                    'rotacao_graus' => 0, 'contornos' => [[[0, 0], [20, 0], [20, 10], [0, 10]]],
                ]], 'pecas_nao_posicionadas' => [], 'area_utilizada_pct' => 2,
                'sobra_contorno' => [[30, 0], [100, 0], [100, 100], [30, 100]],
            ]),
            '*/avaliar' => Http::response(['area_utilizada_pct' => 2,
                'sobra_contorno' => [[30, 0], [100, 0], [100, 100], [30, 100]]]),
        ]);

        $this->postJson(route('pedidos.editor.organizar', $pedido))->assertOk()
            ->assertJsonPath('posicoes.0.x', 10)
            ->assertJsonPath('posicoes.0.instancia', 0)
            ->assertJsonCount(1, 'trajetos_cnc');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/nesting')
            && $request['incumbent']['pecas_posicionadas'][0]['id'] === 7
            && $request['incumbent']['pecas_posicionadas'][0]['instancia'] === 0
            && $request['incumbent']['pecas_posicionadas'][0]['x'] === 0);
        $this->assertSame(0, $pedido->refresh()->resultado_nesting_json['pecas_posicionadas'][0]['x']);
    }

    public function test_non_ready_order_cannot_open_editor(): void
    {
        $this->actingAs(User::factory()->create());
        $pedido = PedidoCorte::factory()->create();
        $this->get(route('pedidos.editor', $pedido))->assertStatus(409);
    }

    public function test_reorganization_allows_smaller_remnant_when_thickness_clearance_increases(): void
    {
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $this->actingAs(User::factory()->create());
        $pedido = $this->readyOrder();
        $pedido->update([
            'resultado_nesting_json' => [...$pedido->resultado_nesting_json,
                'espacamento_minimo_mm' => 1, 'distancia_pecas_mm' => 1, 'margem_borda_mm' => 1],
            'sobra_aproveitavel_json' => [[20, 0], [100, 0], [100, 100], [20, 100]],
        ]);
        $pedido->chapa->espessura->update(['espacamento_minimo_mm' => 3]);
        Http::fake([
            '*/nesting' => Http::response([
                'pecas_posicionadas' => [[
                    'id' => 7, 'instancia' => 0, 'x' => 10, 'y' => 5,
                    'rotacao_graus' => 0, 'contornos' => [[[0, 0], [20, 0], [20, 10], [0, 10]]],
                ]], 'pecas_nao_posicionadas' => [], 'area_utilizada_pct' => 2,
                'sobra_contorno' => [[30, 0], [100, 0], [100, 100], [30, 100]],
            ]),
            '*/avaliar' => Http::response(['area_utilizada_pct' => 2,
                'sobra_contorno' => [[30, 0], [100, 0], [100, 100], [30, 100]]]),
        ]);

        $this->postJson(route('pedidos.editor.organizar', $pedido))->assertOk()
            ->assertJsonPath('posicoes.0.x', 10);
        $this->assertEquals(1, $pedido->refresh()->resultado_nesting_json['espacamento_minimo_mm']);
    }

    private function readyOrder(): PedidoCorte
    {
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 100]);

        return PedidoCorte::factory()->create([
            'chapa_id' => $chapa->id,
            'status' => StatusPedido::Pronto,
            'tempo_corte_estimado_seg' => 3,
            'resultado_nesting_json' => [
                'area_utilizada_pct' => 2,
                'pecas_posicionadas' => [[
                    'id' => 7, 'instancia' => 0, 'x' => 0, 'y' => 0,
                    'rotacao_graus' => 0,
                    'contornos' => [[[0, 0], [20, 0], [20, 10], [0, 10]]],
                ]],
            ],
        ]);
    }
}
