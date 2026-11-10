<?php

namespace App\Jobs;

use App\Enums\StatusProcessamento;
use App\Models\Peca;
use App\Services\GeometriaDxf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtrairGeometriaDxf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $pecaId) {}

    public function handle(GeometriaDxf $geometria): void
    {
        $peca = Peca::find($this->pecaId);
        if (! $peca) {
            Log::warning('Peça excluída antes da extração DXF', ['peca_id' => $this->pecaId]);

            return;
        }

        $arquivo = $peca->arquivo_dxf_original;
        try {
            $payload = $geometria->extrair($arquivo);
            // Ignore stale jobs if the DXF was replaced while processing.
            Peca::whereKey($peca->id)->where('arquivo_dxf_original', $arquivo)->update([
                'contorno_json' => json_encode($payload['contornos'], JSON_THROW_ON_ERROR),
                'largura_bbox_mm' => $payload['bounding_box']['largura_mm'],
                'altura_bbox_mm' => $payload['bounding_box']['altura_mm'],
                'status_processamento' => StatusProcessamento::Processado->value,
                'erro_mensagem' => null,
            ]);
        } catch (Throwable $error) {
            $message = mb_substr($error->getMessage(), 0, 2000);
            Peca::whereKey($peca->id)->where('arquivo_dxf_original', $arquivo)->update([
                'status_processamento' => StatusProcessamento::Erro->value,
                'erro_mensagem' => $message,
                'contorno_json' => null,
                'largura_bbox_mm' => null,
                'altura_bbox_mm' => null,
            ]);
            Log::error('Falha na extração de geometria DXF', ['peca_id' => $peca->id, 'erro' => $message]);
        }
    }
}
