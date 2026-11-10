<?php

namespace App\Services;

use App\Models\Chapa;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MotorNesting
{
    public function __construct(private PcWorkerBridge $pcWorker) {}

    public function avaliar(Chapa $chapa, array $posicoes, array $seguranca = []): array
    {
        $payload = [
            'chapa' => [
                'largura_mm' => (float) $chapa->largura_mm,
                'altura_mm' => (float) $chapa->altura_mm,
                'contorno' => $chapa->contorno_json,
            ],
            'pecas_posicionadas' => $posicoes,
            'margem_borda_mm' => (float) ($seguranca['margem_borda_mm'] ?? 0),
            'distancia_pecas_mm' => (float) ($seguranca['distancia_pecas_mm'] ?? 0),
        ];
        if (config('nesting.pc_worker_enabled')) {
            $result = $this->pcWorker->solicitar('avaliar', $payload, 120);
        } else {
            $request = Http::connectTimeout(5)->timeout(60);
            if (config('nesting.engine_token')) {
                $request = $request->withToken(config('nesting.engine_token'));
            }
            $response = $request->post(rtrim(config('nesting.engine_url'), '/').'/avaliar', $payload);
            if ($response->failed()) {
                throw ValidationException::withMessages([
                    'posicoes' => $response->json('erro') ?: 'Não foi possível validar o arranjo.',
                ]);
            }
            $result = $response->json();
        }
        if (! is_array($result) || ! is_numeric($result['area_utilizada_pct'] ?? null)) {
            throw new RuntimeException('Resposta inválida da validação do arranjo.');
        }

        return $result;
    }

    public function executar(Chapa $chapa, array $pecas, array $seguranca = []): array
    {
        $payload = [
            'chapa' => [
                'largura_mm' => (float) $chapa->largura_mm,
                'altura_mm' => (float) $chapa->altura_mm,
                'contorno' => $chapa->contorno_json,
            ],
            'pecas' => $pecas,
            'margem_borda_mm' => (float) ($seguranca['margem_borda_mm'] ?? 0),
            'distancia_pecas_mm' => (float) ($seguranca['distancia_pecas_mm'] ?? 0),
        ];
        // Reorganization starts from the saved layout. Reindex by the grouped
        // input order; the engine reconstructs and validates its geometry.
        $saved = collect($seguranca['pecas_posicionadas'] ?? [])->groupBy('id');
        if ($saved->isNotEmpty()) {
            $incumbent = [];
            foreach ($pecas as $peca) {
                foreach ($saved->get($peca['id'], []) as $position) {
                    $incumbent[] = [...$position, 'instancia' => count($incumbent)];
                }
            }
            $payload['incumbent'] = ['pecas_posicionadas' => $incumbent];
        }
        if (config('nesting.pc_worker_enabled')) {
            $result = $this->pcWorker->solicitar('nesting', $payload, 150);
        } else {
            $request = Http::connectTimeout(5)->timeout(60);
            if (config('nesting.engine_token')) {
                $request = $request->withToken(config('nesting.engine_token'));
            }
            $response = $request->post(rtrim(config('nesting.engine_url'), '/').'/nesting', $payload);
            $response->throw();
            $result = $response->json();
        }
        if (! is_array($result) || ! is_array($result['pecas_posicionadas'] ?? null)
            || ! is_array($result['pecas_nao_posicionadas'] ?? null)
            || ! is_numeric($result['area_utilizada_pct'] ?? null)) {
            throw new RuntimeException('Resposta inválida do motor de nesting.');
        }
        foreach ($result['pecas_posicionadas'] as $item) {
            if (! isset($item['id'], $item['instancia'], $item['x'], $item['y'], $item['rotacao_graus'], $item['contornos'])) {
                throw new RuntimeException('Resposta incompleta do motor de nesting.');
            }
        }

        return $result;
    }
}
