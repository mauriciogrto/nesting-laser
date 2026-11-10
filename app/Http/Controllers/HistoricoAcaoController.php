<?php

namespace App\Http\Controllers;

use App\Models\HistoricoAcao;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HistoricoAcaoController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'acao' => ['nullable', 'string', 'max:100'],
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
        ]);
        $historico = HistoricoAcao::with('user')
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($filters['acao'] ?? null, fn ($query, $action) => $query->where('acao', $action))
            ->when($filters['data_inicio'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['data_fim'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('id')->paginate(20)->withQueryString();

        return view('historico.index', [
            'historico' => $historico,
            'usuarios' => User::orderBy('name')->get(['id', 'name']),
            'acoes' => HistoricoAcao::distinct()->orderBy('acao')->pluck('acao'),
            'filters' => $filters,
        ]);
    }
}
