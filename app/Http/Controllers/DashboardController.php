<?php

namespace App\Http\Controllers;

use App\Enums\StatusPedido;
use App\Models\Chapa;
use App\Models\Material;
use App\Models\PedidoCorte;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $estoque = Material::query()->leftJoin('chapas', 'chapas.material_id', '=', 'materiais.id')
            ->select('materiais.id', 'materiais.nome', 'materiais.estoque_minimo_alerta')
            ->selectRaw("COALESCE(SUM(CASE WHEN chapas.status = 'disponivel' THEN chapas.quantidade_disponivel ELSE 0 END), 0) AS quantidade")
            ->groupBy('materiais.id', 'materiais.nome', 'materiais.estoque_minimo_alerta')
            ->orderBy('materiais.nome')->get();
        $retalhos = Chapa::query()->where('origem', 'retalho')->where('status', 'disponivel')
            ->selectRaw('COALESCE(SUM(quantidade_disponivel), 0) AS quantidade, COALESCE(SUM(largura_mm * altura_mm * quantidade_disponivel), 0) AS area_mm2')->first();
        $status = PedidoCorte::query()->select('status')->selectRaw('COUNT(*) AS total')
            ->whereIn('status', [StatusPedido::Rascunho->value, StatusPedido::AguardandoNesting->value, StatusPedido::Pronto->value, StatusPedido::EmProducao->value])
            ->groupBy('status')->pluck('total', 'status');
        $ultimos = PedidoCorte::query()
            ->select(['id', 'status', 'chapa_id', 'created_at'])
            ->with('chapa.material')->latest()->limit(10)->get();
        $inicio = now()->subDays(29)->startOfDay();
        $diarios = PedidoCorte::query()->where('status', StatusPedido::Concluido->value)
            ->where('concluido_em', '>=', $inicio)
            ->selectRaw('DATE(concluido_em) AS dia, COUNT(*) AS total')
            ->groupByRaw('DATE(concluido_em)')->pluck('total', 'dia');
        $semanas = [];
        for ($date = $inicio->copy()->startOfWeek(); $date->lte(now()); $date->addWeek()) {
            $end = $date->copy()->endOfWeek();
            $total = 0;
            foreach ($diarios as $day => $count) {
                if ($day >= $date->toDateString() && $day <= $end->toDateString()) {
                    $total += $count;
                }
            }
            $semanas[] = ['label' => $date->format('d/m'), 'total' => $total];
        }

        return view('dashboard', compact('estoque', 'retalhos', 'status', 'ultimos', 'semanas'));
    }
}
