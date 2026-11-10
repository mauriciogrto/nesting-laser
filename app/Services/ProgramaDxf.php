<?php

namespace App\Services;

use App\Models\Chapa;
use RuntimeException;

class ProgramaDxf
{
    public function __construct(private ExecutorPython $executor) {}

    public function gerar(Chapa $chapa, array $posicoes, string $arquivo, array $seguranca = []): array
    {
        $input = json_encode([
            'chapa' => ['largura_mm' => (float) $chapa->largura_mm,
                'altura_mm' => (float) $chapa->altura_mm, 'contorno' => $chapa->contorno_json],
            'pecas_posicionadas' => $posicoes,
            'feed_mm_min' => (float) $chapa->espessura->velocidade_corte_mm_min,
            'margem_borda_mm' => (float) ($seguranca['margem_borda_mm'] ?? 0),
            'lead_in_mm' => (float) ($seguranca['lead_in_mm'] ?? $chapa->espessura->lead_in_mm ?? 2),
            'usar_tabs' => (bool) ($seguranca['usar_tabs'] ?? false),
            'tamanho_tab_mm' => (float) ($seguranca['tamanho_tab_mm'] ?? 1),
        ], JSON_THROW_ON_ERROR);
        $resultado = $this->executor->executar('gerar_dxf_saida.py', [$arquivo], $input);
        $result = json_decode($resultado['saida'], true);
        if (! $resultado['sucesso'] || ! is_array($result) || isset($result['erro'])) {
            throw new RuntimeException($result['erro'] ?? (trim($resultado['erro']) ?: 'Falha ao gerar DXF técnico.'));
        }

        return $result;
    }
}
