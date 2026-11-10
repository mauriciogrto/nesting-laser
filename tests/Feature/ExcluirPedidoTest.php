<?php

namespace Tests\Feature;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Models\Chapa;
use App\Models\HistoricoAcao;
use App\Models\PedidoCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExcluirPedidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_excluding_exported_order_restores_sheet_and_withdraws_unused_remnant(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $sheet = Chapa::factory()->create(['quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
        $remnant = Chapa::factory()->create(['material_id' => $sheet->material_id,
            'espessura_id' => $sheet->espessura_id, 'origem' => OrigemChapa::Retalho,
            'chapa_pai_id' => $sheet->id, 'quantidade_disponivel' => 1]);
        Storage::disk('local')->put('saidas/teste.cnc', 'CNC');
        $order = PedidoCorte::factory()->create(['chapa_id' => $sheet->id,
            'retalho_chapa_id' => $remnant->id, 'status' => StatusPedido::Pronto,
            'exportado_em' => now(), 'estoque_baixado_em' => now(), 'cnc_saida' => 'saidas/teste.cnc']);

        $this->delete(route('pedidos.destroy', $order))->assertRedirect(route('pedidos.index'));

        $this->assertModelMissing($order);
        $this->assertSame(1, $sheet->refresh()->quantidade_disponivel);
        $this->assertSame(StatusChapa::Disponivel, $sheet->status);
        $this->assertSame(0, $remnant->refresh()->quantidade_disponivel);
        $this->assertSame(StatusChapa::Baixada, $remnant->status);
        Storage::disk('local')->assertMissing('saidas/teste.cnc');
        $this->assertDatabaseHas('movimentacoes_estoque', ['chapa_id' => $sheet->id,
            'tipo' => 'entrada', 'observacao' => 'Chapa devolvida pela exclusão do pedido']);
        $this->assertTrue(HistoricoAcao::where('acao', 'exclusao_pedido')->where('entidade_id', $order->id)->exists());
    }

    public function test_exclusion_is_blocked_after_remnant_use_or_production_start(): void
    {
        $this->actingAs(User::factory()->create());
        $sheet = Chapa::factory()->create(['quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
        $remnant = Chapa::factory()->create(['material_id' => $sheet->material_id,
            'espessura_id' => $sheet->espessura_id, 'origem' => OrigemChapa::Retalho,
            'chapa_pai_id' => $sheet->id, 'quantidade_disponivel' => 1]);
        $order = PedidoCorte::factory()->create(['chapa_id' => $sheet->id,
            'retalho_chapa_id' => $remnant->id, 'status' => StatusPedido::Pronto,
            'exportado_em' => now(), 'estoque_baixado_em' => now()]);
        PedidoCorte::factory()->create(['chapa_id' => $remnant->id, 'status' => StatusPedido::Pronto]);

        $this->delete(route('pedidos.destroy', $order))->assertSessionHasErrors('pedido');
        $this->assertModelExists($order);
        $this->assertSame(0, $sheet->refresh()->quantidade_disponivel);

        $order->update(['status' => StatusPedido::EmProducao]);
        $this->delete(route('pedidos.destroy', $order))->assertSessionHasErrors('pedido');
        $this->assertModelExists($order);
    }
}
