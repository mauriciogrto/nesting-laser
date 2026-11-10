<?php

namespace App\Http\Controllers;

use App\Enums\StatusPedido;
use App\Models\Chapa;
use App\Models\PedidoCorte;
use App\Services\EstoqueDoArranjo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProducaoController extends Controller
{
    public function iniciar(PedidoCorte $pedido): RedirectResponse
    {
        DB::transaction(function () use ($pedido): void {
            $locked = PedidoCorte::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StatusPedido::Pronto || ! $locked->estoque_baixado_em) {
                throw ValidationException::withMessages(['pedido' => 'Exporte o arranjo antes de iniciar a produção.']);
            }
            $locked->update(['status' => StatusPedido::EmProducao]);
        });

        return redirect()->route('pedidos.show', $pedido)->with('status', 'Produção iniciada.');
    }

    public function concluir(Request $request, PedidoCorte $pedido, EstoqueDoArranjo $estoque): RedirectResponse
    {
        $data = $request->validate([
            'tempo_corte_real_seg' => ['nullable', 'integer', 'min:1', 'max:999999999'],
        ]);
        DB::transaction(function () use ($pedido, $data, $estoque): void {
            $locked = PedidoCorte::whereKey($pedido->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== StatusPedido::EmProducao) {
                throw ValidationException::withMessages(['pedido' => 'Somente pedidos em produção podem ser concluídos.']);
            }
            if (! $locked->estoque_baixado_em) {
                $chapa = Chapa::whereKey($locked->chapa_id)->lockForUpdate()->firstOrFail();
                $estoque->baixarNaExportacao($locked, $chapa);
            }
            $locked->update(['status' => StatusPedido::Concluido,
                'tempo_corte_real_seg' => $data['tempo_corte_real_seg'] ?? null,
                'concluido_em' => now(), 'data_entrega' => $locked->data_entrega ?? now()->toDateString()]);
            $estoque->atualizarCustoTempo($locked, $locked->chapa);
        });

        return redirect()->route('pedidos.show', $pedido)->with('status', 'Produção concluída.');
    }
}
