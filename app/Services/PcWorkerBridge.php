<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PcWorkerBridge
{
    private const BASE = 'engine-bridge';

    public function solicitar(string $operacao, array $payload, int $timeoutSegundos = 180): array
    {
        $disk = Storage::disk('local');
        $heartbeat = self::BASE.'/heartbeat';
        if (! $disk->exists($heartbeat) || time() - $disk->lastModified($heartbeat) > 75) {
            throw new WorkerIndisponivel('O computador de otimização está desconectado. Ligue o processador local e tente novamente.');
        }

        $id = (string) Str::uuid();
        $pedido = self::BASE.'/requests/'.$id.'.json';
        $resposta = self::BASE.'/responses/'.$id.'.json';
        $temporario = self::BASE.'/requests/'.$id.'.tmp';
        $disk->makeDirectory(self::BASE.'/requests');
        $disk->makeDirectory(self::BASE.'/responses');
        if (! $disk->put($temporario, json_encode([
            'id' => $id, 'operacao' => $operacao, 'payload' => $payload,
        ], JSON_THROW_ON_ERROR)) || ! $disk->move($temporario, $pedido)) {
            throw new RuntimeException('Não foi possível enviar o cálculo ao computador de otimização.');
        }

        $deadline = microtime(true) + $timeoutSegundos;
        try {
            set_time_limit(max(60, $timeoutSegundos + 10));
            while (microtime(true) < $deadline) {
                if ($disk->exists($resposta)) {
                    $data = json_decode((string) $disk->get($resposta), true);
                    if (! is_array($data) || ($data['id'] ?? null) !== $id) {
                        throw new RuntimeException('Resposta inválida do computador de otimização.');
                    }
                    if (! ($data['ok'] ?? false)) {
                        throw new RuntimeException((string) ($data['erro'] ?? 'Falha ao processar o arranjo.'));
                    }
                    if (! is_array($data['result'] ?? null)) {
                        throw new RuntimeException('Resultado inválido do computador de otimização.');
                    }

                    return $data['result'];
                }
                usleep(250_000);
            }
            throw new WorkerIndisponivel('O computador de otimização não concluiu o cálculo a tempo. Confira se ele está ligado e tente novamente.');
        } finally {
            $disk->delete([$pedido, $resposta, $temporario]);
        }
    }
}
