<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GeometriaDxf
{
    public function __construct(private ExecutorPython $executor) {}

    public function extrair(string $arquivo): array
    {
        if (! Storage::disk('local')->exists($arquivo)) {
            throw new RuntimeException('Arquivo DXF não encontrado no armazenamento privado.');
        }
        $resultado = $this->executor->executar('extrair_geometria.py', [Storage::disk('local')->path($arquivo)]);
        $payload = json_decode($resultado['saida'], true);
        if (! $resultado['sucesso'] || ! is_array($payload) || isset($payload['erro'])) {
            throw new RuntimeException($payload['erro'] ?? (trim($resultado['erro']) ?: 'Falha ao extrair geometria.'));
        }
        if (! is_array($payload['contornos'] ?? null) || ! count($payload['contornos'])
            || ! is_numeric($payload['bounding_box']['largura_mm'] ?? null)
            || ! is_numeric($payload['bounding_box']['altura_mm'] ?? null)) {
            throw new RuntimeException('Resposta inválida do parser DXF.');
        }

        return $payload;
    }

    public function aplicarOffset(array $contornos, float $valor): array
    {
        $resultado = $this->executor->executar('aplicar_offset.py', [], json_encode([
            'contornos' => $contornos, 'offset_mm' => $valor,
        ], JSON_THROW_ON_ERROR));
        $result = json_decode($resultado['saida'], true);
        if (! $resultado['sucesso'] || ! is_array($result) || isset($result['erro']) || ! is_array($result['contornos'] ?? null)) {
            throw new RuntimeException($result['erro'] ?? (trim($resultado['erro']) ?: 'Falha no offset de kerf.'));
        }

        return $result['contornos'];
    }
}
