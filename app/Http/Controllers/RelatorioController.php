<?php

namespace App\Http\Controllers;

use App\Models\Material;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RelatorioController extends Controller
{
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'data_inicio' => ['nullable', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'material_id' => ['nullable', 'integer', 'exists:materiais,id'],
        ]);

        return [
            'data_inicio' => $data['data_inicio'] ?? now()->subDays(29)->toDateString(),
            'data_fim' => $data['data_fim'] ?? now()->toDateString(),
            'material_id' => $data['material_id'] ?? null,
        ];
    }

    private function materials()
    {
        return Material::orderBy('nome')->get(['id', 'nome']);
    }

    private function pedidos(array $filters): Builder
    {
        return DB::table('pedidos_corte as p')->join('chapas as c', 'c.id', '=', 'p.chapa_id')
            ->join('materiais as m', 'm.id', '=', 'c.material_id')
            ->where('p.status', 'concluido')
            ->whereDate('p.concluido_em', '>=', $filters['data_inicio'])
            ->whereDate('p.concluido_em', '<=', $filters['data_fim'])
            ->when($filters['material_id'], fn ($query, $id) => $query->where('c.material_id', $id));
    }

    private function movimentos(array $filters): Builder
    {
        return DB::table('movimentacoes_estoque as e')->join('chapas as c', 'c.id', '=', 'e.chapa_id')
            ->join('materiais as m', 'm.id', '=', 'c.material_id')
            ->whereDate('e.created_at', '>=', $filters['data_inicio'])
            ->whereDate('e.created_at', '<=', $filters['data_fim'])
            ->when($filters['material_id'], fn ($query, $id) => $query->where('c.material_id', $id));
    }

    public function index(): View
    {
        return view('relatorios.index');
    }

    public function aproveitamento(Request $request): View
    {
        $filters = $this->filters($request);
        $base = $this->pedidos($filters)->whereNotNull('p.area_chapa_mm2')->whereNotNull('p.area_pecas_mm2');
        $media = (clone $base)->selectRaw('AVG(CASE WHEN p.area_chapa_mm2 > 0 THEN p.area_pecas_mm2 * 100.0 / p.area_chapa_mm2 END) AS media')->first()->media;
        $rows = $base->select('p.id', 'p.concluido_em', 'p.area_chapa_mm2', 'p.area_pecas_mm2', 'c.id as chapa_id', 'm.nome as material')
            ->selectRaw('CASE WHEN p.area_chapa_mm2 > 0 THEN p.area_pecas_mm2 * 100.0 / p.area_chapa_mm2 END AS percentual')
            ->orderByDesc('p.concluido_em')->paginate(20)->withQueryString();

        return view('relatorios.aproveitamento', ['rows' => $rows, 'media' => $media, 'filters' => $filters, 'materiais' => $this->materials()]);
    }

    public function aproveitamentoCsv(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->pedidos($filters)->whereNotNull('p.area_chapa_mm2')->whereNotNull('p.area_pecas_mm2')
            ->select('p.id', 'p.concluido_em', 'p.area_chapa_mm2', 'p.area_pecas_mm2', 'c.id as chapa_id', 'm.nome as material')
            ->selectRaw('CASE WHEN p.area_chapa_mm2 > 0 THEN p.area_pecas_mm2 * 100.0 / p.area_chapa_mm2 END AS percentual')
            ->orderBy('p.id');

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Pedido', 'Data', 'Chapa', 'Material', 'Área chapa mm²', 'Área peças mm²', 'Aproveitamento %'], ';');
            foreach ($query->cursor() as $row) {
                fputcsv($out, [$row->id, $row->concluido_em, $row->chapa_id, $row->material, $row->area_chapa_mm2, $row->area_pecas_mm2, $row->percentual], ';');
            }
            fclose($out);
        }, 'aproveitamento.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function tempos(Request $request): View
    {
        $filters = $this->filters($request);
        $base = $this->pedidos($filters);
        $rows = $base->select('p.id', 'p.concluido_em', 'p.tempo_corte_estimado_seg', 'p.tempo_corte_real_seg', 'm.nome as material')
            ->selectRaw('CASE WHEN p.tempo_corte_estimado_seg > 0 AND p.tempo_corte_real_seg IS NOT NULL THEN (p.tempo_corte_real_seg - p.tempo_corte_estimado_seg) * 100.0 / p.tempo_corte_estimado_seg END AS diferenca_pct')
            ->orderByDesc('p.concluido_em')->paginate(20)->withQueryString();

        return view('relatorios.tempos', ['rows' => $rows, 'filters' => $filters, 'materiais' => $this->materials()]);
    }

    public function consumo(Request $request): View
    {
        $filters = $this->filters($request);
        $rows = $this->movimentos($filters)->join('espessuras_material as em', 'em.id', '=', 'c.espessura_id')
            ->where('e.tipo', 'baixa')->select('m.nome as material', 'em.valor_mm as espessura_mm')
            ->selectRaw('SUM(e.quantidade) AS quantidade')
            ->groupBy('m.id', 'm.nome', 'em.id', 'em.valor_mm')->orderBy('m.nome')->orderBy('em.valor_mm')
            ->paginate(20)->withQueryString();

        return view('relatorios.consumo', ['rows' => $rows, 'filters' => $filters, 'materiais' => $this->materials()]);
    }

    public function retalhos(Request $request): View
    {
        $filters = $this->filters($request);
        $rows = $this->movimentos($filters)->where('c.origem', 'retalho')
            ->whereIn('e.tipo', ['geracao_retalho', 'baixa'])
            ->select('m.nome as material')
            ->selectRaw("SUM(CASE WHEN e.tipo = 'geracao_retalho' THEN e.quantidade ELSE 0 END) AS gerados")
            ->selectRaw("SUM(CASE WHEN e.tipo = 'baixa' THEN e.quantidade ELSE 0 END) AS reaproveitados")
            ->groupBy('m.id', 'm.nome')->orderBy('m.nome')->paginate(20)->withQueryString();

        return view('relatorios.retalhos', ['rows' => $rows, 'filters' => $filters, 'materiais' => $this->materials()]);
    }
}
