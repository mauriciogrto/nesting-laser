<?php

namespace App\Http\Controllers;

use App\Enums\TipoMovimentacao;
use App\Models\MovimentacaoEstoque;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MovimentacaoEstoqueController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'chapa_id' => ['nullable', 'integer', 'exists:chapas,id'],
            'tipo' => ['nullable', Rule::enum(TipoMovimentacao::class)],
        ]);
        $movimentacoes = MovimentacaoEstoque::with(['chapa.material', 'pedido'])
            ->when($filters['chapa_id'] ?? null, fn ($query, $id) => $query->where('chapa_id', $id))
            ->when($filters['tipo'] ?? null, fn ($query, $type) => $query->where('tipo', $type))
            ->latest('id')->paginate(20)->withQueryString();

        return view('movimentacoes.index', compact('movimentacoes', 'filters'));
    }
}
