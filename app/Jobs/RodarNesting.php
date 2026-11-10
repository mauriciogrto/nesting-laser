<?php

namespace App\Jobs;

use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Enums\StatusProcessamento;
use App\Models\PedidoCorte;
use App\Services\AreaGeometria;
use App\Services\CalculadoraTempoCorte;
use App\Services\GeometriaDxf;
use App\Services\MotorNesting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class RodarNesting implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $pedidoId) {}

    public function handle(CalculadoraTempoCorte $calculadora, AreaGeometria $area, GeometriaDxf $geometria, MotorNesting $motor): void
    {
        $pedido = PedidoCorte::with(['chapa.espessura', 'pecas'])->find($this->pedidoId);
        if (! $pedido || $pedido->status !== StatusPedido::AguardandoNesting) {
            return;
        }
        try {
            $chapa = $pedido->chapa ?? throw new RuntimeException('Pedido sem chapa selecionada.');
            if ($chapa->status !== StatusChapa::Disponivel || $chapa->quantidade_disponivel < 1) {
                throw new RuntimeException('A chapa selecionada não está disponível.');
            }
            if ($pedido->pecas->isEmpty()) {
                throw new RuntimeException('Pedido sem peças.');
            }
            $kerf = (float) ($chapa->espessura->kerf_mm ?? 0);
            $espacamento = (float) $chapa->espessura->espacamento_minimo_mm;
            if ($espacamento <= $kerf) {
                throw new RuntimeException('Configure um espaçamento térmico maior que o kerf na espessura da chapa.');
            }
            $pecas = [];
            foreach ($pedido->pecas as $peca) {
                if ($peca->status_processamento !== StatusProcessamento::Processado || ! $peca->contorno_json) {
                    throw new RuntimeException("Peça {$peca->id} ainda não possui geometria processada.");
                }
                $contornos = $kerf > 0 ? $geometria->aplicarOffset($peca->contorno_json, $kerf / 2) : $peca->contorno_json;
                $pecas[] = [
                    'id' => $peca->id,
                    'contornos' => $contornos,
                    'quantidade' => (int) $peca->pivot->quantidade,
                ];
            }

            $result = $motor->executar($chapa, $pecas, [
                'distancia_pecas_mm' => $espacamento, 'margem_borda_mm' => $espacamento,
            ]);
            $result['kerf_mm'] = $kerf;
            $result['espacamento_minimo_mm'] = $espacamento;
            $result['distancia_pecas_mm'] = $espacamento;
            $result['margem_borda_mm'] = $espacamento;
            $result['lead_in_mm'] = (float) $chapa->espessura->lead_in_mm;
            $result['usar_tabs'] = (bool) $pedido->usar_tabs;
            $result['tamanho_tab_mm'] = (float) $pedido->tamanho_tab_mm;
            $expected = array_sum(array_column($pecas, 'quantidade'));
            if (! is_array($result['pecas_posicionadas'] ?? null) || count($result['pecas_posicionadas']) !== $expected || ! empty($result['pecas_nao_posicionadas'])) {
                throw new RuntimeException('O motor não conseguiu posicionar todas as peças na chapa.');
            }
            $pedido->update([
                'resultado_nesting_json' => $result,
                'tempo_corte_estimado_seg' => $calculadora->calcular($result['pecas_posicionadas'], $chapa->espessura, $result),
                'sobra_aproveitavel_json' => $result['sobra_contorno'] ?? null,
                'status' => StatusPedido::Pronto,
                'erro_mensagem' => null,
                'area_chapa_mm2' => $area->chapa($chapa->contorno_json ?? [], (float) $chapa->largura_mm, (float) $chapa->altura_mm),
                'area_pecas_mm2' => $area->pecas($result['pecas_posicionadas']),
            ]);
        } catch (Throwable $error) {
            $message = mb_substr($error->getMessage(), 0, 2000);
            $pedido->update(['status' => StatusPedido::Erro, 'erro_mensagem' => $message]);
            Log::error('Falha ao rodar nesting', ['pedido_id' => $pedido->id, 'erro' => $message]);
        }
    }
}
