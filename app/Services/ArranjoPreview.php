<?php

namespace App\Services;

use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Models\Chapa;
use App\Models\PedidoCorte;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ArranjoPreview
{
    public const TTL_MINUTES = 15;

    public const STATUS_COM_CHAPA_ALOCADA = [
        StatusPedido::Rascunho->value, StatusPedido::AguardandoNesting->value,
        StatusPedido::Pronto->value, StatusPedido::EmProducao->value,
    ];

    private function key(string $token): string
    {
        return 'arranjo-preview:'.$token;
    }

    public function obter(string $token, int $userId): array
    {
        $preview = Cache::get($this->key($token));
        abort_unless($preview, 410, 'O preview expirou. Envie os arquivos novamente.');
        abort_unless($preview['user_id'] === $userId, 404);

        return $preview;
    }

    public function salvar(string $token, array $preview): void
    {
        Cache::put($this->key($token), $preview, now()->addMinutes(self::TTL_MINUTES));
        Storage::disk('local')->put('previews/'.$token.'/.active', now()->toIso8601String());
    }

    public function esquecer(string $token): void
    {
        Cache::forget($this->key($token));
        Storage::disk('local')->deleteDirectory('previews/'.$token);
    }

    public function todasDisponiveis()
    {
        return Chapa::query()->where('status', StatusChapa::Disponivel->value)->where('quantidade_disponivel', '>', 0)
            ->whereRaw('chapas.quantidade_disponivel > (SELECT COUNT(*) FROM pedidos_corte WHERE pedidos_corte.chapa_id = chapas.id AND pedidos_corte.estoque_baixado_em IS NULL AND pedidos_corte.status IN (?, ?, ?, ?))', self::STATUS_COM_CHAPA_ALOCADA)
            ->withCount(['pedidos as pedidos_ativos_count' => fn ($query) => $query->whereNull('estoque_baixado_em')->whereIn('status', self::STATUS_COM_CHAPA_ALOCADA)]);
    }

    public function disponiveis(int $materialId, int $espessuraId)
    {
        return $this->todasDisponiveis()->where('material_id', $materialId)->where('espessura_id', $espessuraId);
    }

    public function escolher(int $materialId, int $espessuraId, int $chapaId): Chapa
    {
        $chapa = $this->disponiveis($materialId, $espessuraId)->whereKey($chapaId)->first();
        if (! $chapa) {
            throw ValidationException::withMessages(['chapa_id' => 'A chapa escolhida não está disponível para este material e espessura.']);
        }

        return $chapa;
    }

    public function gerar(Chapa $chapa, array $pecas, MotorNesting $motor, CalculadoraTempoCorte $calculadora,
        array $seguranca = []): array
    {
        $input = array_map(fn ($peca) => [
            'id' => $peca['token'], 'contornos' => $peca['contornos_compensados'], 'quantidade' => $peca['quantidade'],
        ], $pecas);
        $result = $motor->executar($chapa, $input, $seguranca);
        $result['margem_borda_mm'] = (float) ($seguranca['margem_borda_mm'] ?? 0);
        $result['distancia_pecas_mm'] = (float) ($seguranca['distancia_pecas_mm'] ?? 0);
        $result['espacamento_minimo_mm'] = (float) ($seguranca['espacamento_minimo_mm'] ?? $seguranca['distancia_pecas_mm'] ?? 0);
        $result['lead_in_mm'] = (float) ($seguranca['lead_in_mm'] ?? $chapa->espessura->lead_in_mm ?? 2);
        $result['usar_tabs'] = (bool) ($seguranca['usar_tabs'] ?? false);
        $result['tamanho_tab_mm'] = (float) ($seguranca['tamanho_tab_mm'] ?? 1);
        if (isset($seguranca['kerf_mm'])) {
            $result['kerf_mm'] = (float) $seguranca['kerf_mm'];
        }
        $total = array_sum(array_column($pecas, 'quantidade'));
        if (count($result['pecas_posicionadas']) + count($result['pecas_nao_posicionadas']) !== $total) {
            throw new \RuntimeException('O motor retornou uma quantidade incorreta de peças.');
        }
        $expected = [];
        $instance = 0;
        foreach ($pecas as $peca) {
            for ($i = 0; $i < $peca['quantidade']; $i++) {
                $expected[$instance++] = $peca['token'];
            }
        }
        foreach (array_merge($result['pecas_posicionadas'], $result['pecas_nao_posicionadas']) as $item) {
            $instance = $item['instancia'] ?? null;
            if (! is_int($instance) || ! isset($expected[$instance]) || $expected[$instance] !== ($item['id'] ?? null)) {
                throw new \RuntimeException('O motor retornou instâncias de peças inválidas.');
            }
            unset($expected[$instance]);
        }

        return [
            'resultado' => $result,
            'tempo_corte_estimado_seg' => $calculadora->calcular($result['pecas_posicionadas'], $chapa->espessura, $result),
        ];
    }

    public function planejar(int $materialId, int $espessuraId, array $pecas, MotorNesting $motor,
        CalculadoraTempoCorte $calculadora, ?Chapa $preferida = null, array $seguranca = []): array
    {
        $chapas = $this->disponiveis($materialId, $espessuraId)->with('espessura')
            ->orderByRaw("CASE WHEN origem = 'retalho' THEN 0 ELSE 1 END")
            ->orderByRaw('largura_mm * altura_mm')->orderBy('id')->get();
        if ($chapas->isEmpty()) {
            throw ValidationException::withMessages(['chapa_id' => 'Não há chapas disponíveis para este material e espessura.']);
        }
        if ($preferida) {
            $chapas = $chapas->sortBy(fn (Chapa $chapa) => $chapa->id === $preferida->id ? 0 : 1)->values();
        }

        $restantes = array_column($pecas, 'quantidade', 'token');
        $planos = [];
        $primeiroSemEncaixe = null;
        foreach ($chapas as $chapa) {
            for ($uso = 0; $uso < $chapa->quantidade_disponivel - $chapa->pedidos_ativos_count; $uso++) {
                $pendentes = array_values(array_filter(array_map(function (array $peca) use ($restantes): array {
                    return [...$peca, 'quantidade' => $restantes[$peca['token']]];
                }, $pecas), fn (array $peca) => $peca['quantidade'] > 0));
                if (! $pendentes) {
                    break 2;
                }
                $gerado = $this->gerar($chapa, $pendentes, $motor, $calculadora, $seguranca);
                $posicionadas = $gerado['resultado']['pecas_posicionadas'];
                if (! $posicionadas) {
                    $primeiroSemEncaixe ??= ['chapa_id' => $chapa->id,
                        'chapa_updated_at' => $chapa->updated_at?->toISOString(), ...$gerado];
                    break;
                }
                foreach ($posicionadas as $item) {
                    $restantes[$item['id']]--;
                }
                $planos[] = ['chapa_id' => $chapa->id,
                    'chapa_updated_at' => $chapa->updated_at?->toISOString(), ...$gerado];
            }
        }
        if (! $planos && $primeiroSemEncaixe) {
            $planos[] = $primeiroSemEncaixe;
        }
        $naoPosicionadas = [];
        foreach ($restantes as $token => $quantidade) {
            for ($i = 0; $i < $quantidade; $i++) {
                $naoPosicionadas[] = ['id' => $token];
            }
        }

        return ['planos' => $planos, 'pecas_nao_posicionadas' => $naoPosicionadas];
    }

    public function planejarRevisao(PedidoCorte $pedido, array $pecas, MotorNesting $motor,
        CalculadoraTempoCorte $calculadora, array $seguranca): array
    {
        $pedido->loadMissing('chapa.espessura', 'chapasInternas.chapa.espessura');
        $reservadas = collect([$pedido->chapa])->concat($pedido->chapasInternas->pluck('chapa'));
        $retalhosAntigos = collect([$pedido->retalho_chapa_id])
            ->concat($pedido->chapasInternas->pluck('retalho_chapa_id'))->filter()->all();
        $disponiveis = $this->disponiveis($pedido->chapa->material_id, $pedido->chapa->espessura_id)
            ->with('espessura')->orderByRaw("CASE WHEN origem = 'retalho' THEN 0 ELSE 1 END")
            ->orderByRaw('largura_mm * altura_mm')->orderBy('id')->get()
            ->reject(fn (Chapa $chapa) => in_array($chapa->id, $retalhosAntigos, true));
        $slots = [];
        foreach ($reservadas as $chapa) {
            $slots[] = ['chapa' => $chapa, 'reservada' => true];
        }
        foreach ($disponiveis as $chapa) {
            for ($uso = 0; $uso < $chapa->quantidade_disponivel - $chapa->pedidos_ativos_count; $uso++) {
                $slots[] = ['chapa' => $chapa, 'reservada' => false];
            }
        }
        $restantes = array_column($pecas, 'quantidade', 'token');
        $planos = [];
        foreach ($slots as $slot) {
            $pendentes = array_values(array_filter(array_map(fn (array $peca) => [...$peca, 'quantidade' => $restantes[$peca['token']]], $pecas),
                fn (array $peca) => $peca['quantidade'] > 0));
            if (! $pendentes) {
                break;
            }
            $gerado = $this->gerar($slot['chapa'], $pendentes, $motor, $calculadora, $seguranca);
            if (! $gerado['resultado']['pecas_posicionadas']) {
                continue;
            }
            foreach ($gerado['resultado']['pecas_posicionadas'] as $item) {
                $restantes[$item['id']]--;
            }
            $planos[] = ['chapa_id' => $slot['chapa']->id,
                'chapa_updated_at' => $slot['chapa']->updated_at?->toISOString(),
                'reservada' => $slot['reservada'], ...$gerado];
        }
        $naoPosicionadas = [];
        foreach ($restantes as $token => $quantidade) {
            for ($i = 0; $i < $quantidade; $i++) {
                $naoPosicionadas[] = ['id' => $token];
            }
        }

        return ['planos' => $planos, 'pecas_nao_posicionadas' => $naoPosicionadas];
    }

    public function limparExpirados(): int
    {
        $disk = Storage::disk('local');
        $removed = 0;
        foreach ($disk->directories('previews') as $directory) {
            $marker = $directory.'/.active';
            $files = $disk->files($directory);
            $latest = $disk->exists($marker) ? $disk->lastModified($marker) : ($files ? max(array_map(fn ($file) => $disk->lastModified($file), $files)) : 0);
            if ($latest < now()->subMinutes(self::TTL_MINUTES + 5)->timestamp) {
                $disk->deleteDirectory($directory);
                $removed++;
            }
        }

        return $removed;
    }
}
