<?php

namespace App\Observers;

use App\Enums\TipoMovimentacao;
use App\Models\HistoricoAcao;
use App\Models\MovimentacaoEstoque;
use Illuminate\Support\Facades\Auth;

class MovimentacaoEstoqueObserver
{
    public function created(MovimentacaoEstoque $movimento): void
    {
        if (! in_array($movimento->tipo, [TipoMovimentacao::Baixa, TipoMovimentacao::GeracaoRetalho], true)) {
            return;
        }
        HistoricoAcao::create([
            'user_id' => Auth::id(),
            'acao' => $movimento->tipo === TipoMovimentacao::Baixa ? 'baixa_estoque' : 'geracao_retalho',
            'entidade' => 'chapa', 'entidade_id' => $movimento->chapa_id,
            'dados_alterados' => ['pedido_id' => $movimento->pedido_corte_id, 'quantidade' => $movimento->quantidade],
        ]);
    }
}
