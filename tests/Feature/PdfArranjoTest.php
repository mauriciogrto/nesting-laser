<?php

namespace Tests\Feature;

use App\Enums\StatusPedido;
use App\Models\Chapa;
use App\Models\PedidoCorte;
use App\Models\User;
use App\Services\PdfArranjo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfArranjoTest extends TestCase
{
    use RefreshDatabase;

    public function test_saved_arrangement_can_be_downloaded_as_pdf(): void
    {
        $pedido = PedidoCorte::factory()->create([
            'nome' => 'Arranjo real', 'status' => StatusPedido::Pronto,
            'resultado_nesting_json' => ['pecas_posicionadas' => [[
                'id' => 1, 'instancia' => 0, 'x' => 0, 'y' => 0, 'rotacao_graus' => 0,
                'contornos' => [[[0, 0], [10, 0], [10, 10], [0, 10]]],
            ]]],
        ]);
        $this->mock(PdfArranjo::class)->shouldReceive('gerar')->once()->andReturn('%PDF-example');
        $this->actingAs(User::factory()->operador()->create());

        $this->get(route('pedidos.show', $pedido))->assertOk()->assertSee('Baixar PDF do arranjo');
        $this->get(route('pedidos.pdf', $pedido))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('%PDF-example', false);
    }

    public function test_pdf_requires_authentication_and_a_saved_layout(): void
    {
        $pedido = PedidoCorte::factory()->create();
        $this->get(route('pedidos.pdf', $pedido))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->operador()->create());
        $this->get(route('pedidos.pdf', $pedido))->assertNotFound();
    }

    public function test_pdf_route_renders_the_current_saved_contours(): void
    {
        config(['services.dxf.python' => (string) env('DXF_PYTHON_BIN', 'python3')]);
        $chapa = Chapa::factory()->create(['largura_mm' => 100, 'altura_mm' => 80]);
        $pedido = PedidoCorte::factory()->create([
            'chapa_id' => $chapa->id, 'status' => StatusPedido::Pronto,
            'resultado_nesting_json' => ['area_utilizada_pct' => 6.25,
                'pecas_posicionadas' => [[
                    'id' => 1, 'instancia' => 0, 'x' => 10, 'y' => 10,
                    'rotacao_graus' => 0,
                    'contornos' => [[[0, 0], [20, 0], [20, 20], [0, 20]]],
                ]]],
        ]);
        $this->actingAs(User::factory()->operador()->create());

        $response = $this->get(route('pedidos.pdf', $pedido))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }
}
