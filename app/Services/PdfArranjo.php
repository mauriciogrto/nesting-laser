<?php

namespace App\Services;

use App\Models\PedidoChapa;
use App\Models\PedidoCorte;
use RuntimeException;

class PdfArranjo
{
    public function __construct(private ExecutorPython $executor) {}

    public function gerar(PedidoCorte $pedido, ?PedidoChapa $plano = null): string
    {
        $pedido->loadMissing(['chapa.material', 'chapa.espessura', 'pecas', 'chapasInternas']);
        $chapa = $plano?->chapa ?? $pedido->chapa;
        $resultado = $plano?->resultado_nesting_json ?? $pedido->resultado_nesting_json;
        $posicoes = $resultado['pecas_posicionadas'] ?? null;
        if (! $chapa || ! is_array($posicoes) || ! count($posicoes)) {
            throw new RuntimeException('Este pedido ainda não possui arranjo para exportar.');
        }
        $catalogo = $pedido->pecas->keyBy('id');
        $tempoEstimado = $plano?->tempo_corte_estimado_seg
            ?? max(0, (int) $pedido->tempo_corte_estimado_seg - (int) $pedido->chapasInternas->sum('tempo_corte_estimado_seg'));
        $custoMaterial = $plano?->custo_material
            ?? max(0, (float) $pedido->custo_material - (float) $pedido->chapasInternas->sum('custo_material'));
        $custoMaquina = $plano?->custo_maquina
            ?? max(0, (float) $pedido->custo_maquina - (float) $pedido->chapasInternas->sum('custo_maquina'));
        $input = json_encode([
            'pedido_id' => $pedido->id,
            'nome' => $pedido->nome,
            'chapa_numero' => $plano?->numero ?? 1,
            'chapa_id' => $chapa->id,
            'gerado_em' => now()->format('d/m/Y H:i'),
            'exportado_em' => $pedido->exportado_em?->format('d/m/Y H:i'),
            'material' => $chapa->material->nome,
            'densidade_kg_m3' => $chapa->material->densidade_kg_m3 ? (float) $chapa->material->densidade_kg_m3 : null,
            'espessura_mm' => (float) $chapa->espessura->valor_mm,
            'tempo_corte_estimado_seg' => (int) $tempoEstimado,
            'velocidade_corte_mm_min' => (float) $chapa->espessura->velocidade_corte_mm_min,
            'tempo_perfuracao_seg' => (float) $chapa->espessura->tempo_perfuracao_seg,
            'custo_material' => (float) $custoMaterial,
            'custo_maquina' => (float) $custoMaquina,
            'kerf_mm' => (float) ($resultado['kerf_mm'] ?? $chapa->espessura->kerf_mm),
            'espacamento_minimo_mm' => (float) ($resultado['espacamento_minimo_mm'] ?? $chapa->espessura->espacamento_minimo_mm),
            'margem_borda_mm' => (float) ($resultado['margem_borda_mm'] ?? $chapa->espessura->espacamento_minimo_mm),
            'lead_in_mm' => (float) ($resultado['lead_in_mm'] ?? $chapa->espessura->lead_in_mm),
            'usar_tabs' => (bool) ($resultado['usar_tabs'] ?? $pedido->usar_tabs),
            'tamanho_tab_mm' => (float) ($resultado['tamanho_tab_mm'] ?? $pedido->tamanho_tab_mm ?? 1),
            'area_utilizada_pct' => $resultado['area_utilizada_pct'] ?? 0,
            'sobra_contorno' => $plano ? $plano->sobra_aproveitavel_json : $pedido->sobra_aproveitavel_json,
            'catalogo' => $catalogo->map(fn ($peca) => [
                'nome' => $peca->nome,
                'largura_mm' => (float) $peca->largura_bbox_mm,
                'altura_mm' => (float) $peca->altura_bbox_mm,
                'contornos_originais' => $peca->contorno_json,
            ])->all(),
            'chapa' => [
                'largura_mm' => (float) $chapa->largura_mm,
                'altura_mm' => (float) $chapa->altura_mm,
                'contorno' => $chapa->contorno_json,
            ],
            'pecas_posicionadas' => array_map(fn (array $item) => [...$item,
                'nome' => $catalogo->get($item['id'])?->nome ?? 'Peça '.$item['id']], $posicoes),
        ], JSON_THROW_ON_ERROR);
        $resultado = $this->executor->executar('gerar_pdf_arranjo.py', [], $input);
        if (! $resultado['sucesso'] || ! str_starts_with($resultado['saida'], '%PDF-')) {
            throw new RuntimeException(trim($resultado['erro']) ?: 'Falha ao gerar PDF do arranjo.');
        }

        return $resultado['saida'];
    }
}
