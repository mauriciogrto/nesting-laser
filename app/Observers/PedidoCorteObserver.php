<?php

namespace App\Observers;

use App\Enums\StatusPedido;
use App\Models\HistoricoAcao;
use App\Models\PedidoCorte;
use Illuminate\Support\Facades\Auth;

class PedidoCorteObserver
{
    public function updated(PedidoCorte $pedido): void
    {
        if (! $pedido->wasChanged('status')) {
            return;
        }
        $from = $pedido->getOriginal('status');
        $before = $from instanceof StatusPedido ? $from->value : (string) $from;
        HistoricoAcao::create([
            'user_id' => Auth::id(), 'acao' => 'status_pedido', 'entidade' => 'pedido_corte', 'entidade_id' => $pedido->id,
            'dados_alterados' => ['de' => $before, 'para' => $pedido->status->value],
        ]);
        if ($pedido->status === StatusPedido::EmProducao) {
            HistoricoAcao::create([
                'user_id' => Auth::id(), 'acao' => 'confirmacao_arranjo', 'entidade' => 'pedido_corte', 'entidade_id' => $pedido->id,
                'dados_alterados' => ['pecas' => count($pedido->resultado_nesting_json['pecas_posicionadas'] ?? [])],
            ]);
        }
    }
}
