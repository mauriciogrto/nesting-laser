<?php

namespace App\Jobs;

use App\Enums\StatusPedido;
use App\Models\PedidoCorte;
use App\Services\ProgramaCnc;
use App\Services\ProgramaDxf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class GerarCncSaida implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $pedidoId) {}

    public function handle(ProgramaCnc $programa, ProgramaDxf $dxf): void
    {
        $pedido = PedidoCorte::with('chapa.espessura')->find($this->pedidoId);
        if (! $pedido || ! in_array($pedido->status, [StatusPedido::Pronto, StatusPedido::EmProducao], true)) {
            return;
        }

        $relative = 'saidas/pedido-'.$pedido->id.'-'.uniqid().'.cnc';
        $relativeDxf = 'saidas/pedido-'.$pedido->id.'-'.uniqid().'.dxf';
        try {
            if (! $pedido->cnc_saida) {
                $posicoes = $pedido->resultado_nesting_json['pecas_posicionadas'] ?? null;
                if (! is_array($posicoes) || ! $pedido->chapa) {
                    throw new RuntimeException('Arranjo confirmado incompleto.');
                }
                Storage::disk('local')->makeDirectory('saidas');
                $absolute = Storage::disk('local')->path($relative);
                $programa->gerar($pedido->chapa, $posicoes, $absolute, $pedido->resultado_nesting_json);
                $dxf->gerar($pedido->chapa, $posicoes, Storage::disk('local')->path($relativeDxf), $pedido->resultado_nesting_json);
                if (! Storage::disk('local')->exists($relative)) {
                    throw new RuntimeException('Programa CNC não foi gravado.');
                }
                if (! Storage::disk('local')->exists($relativeDxf)) {
                    throw new RuntimeException('DXF técnico não foi gravado.');
                }
                $pedido->update(['cnc_saida' => $relative, 'dxf_saida' => $relativeDxf, 'erro_mensagem' => null]);
            }
            foreach ($pedido->chapasInternas()->with('chapa.espessura')->get() as $plano) {
                if ($plano->cnc_saida) {
                    continue;
                }
                $cncPlano = 'saidas/pedido-'.$pedido->id.'-chapa-'.$plano->numero.'-'.uniqid().'.cnc';
                $dxfPlano = 'saidas/pedido-'.$pedido->id.'-chapa-'.$plano->numero.'-'.uniqid().'.dxf';
                try {
                    $posicoes = $plano->resultado_nesting_json['pecas_posicionadas'] ?? null;
                    if (! is_array($posicoes) || ! $plano->chapa) {
                        throw new RuntimeException('Chapa interna sem arranjo.');
                    }
                    $programa->gerar($plano->chapa, $posicoes, Storage::disk('local')->path($cncPlano), $plano->resultado_nesting_json);
                    $dxf->gerar($plano->chapa, $posicoes, Storage::disk('local')->path($dxfPlano), $plano->resultado_nesting_json);
                    if (! Storage::disk('local')->exists($cncPlano) || ! Storage::disk('local')->exists($dxfPlano)) {
                        throw new RuntimeException('Programa da chapa interna não foi gravado.');
                    }
                    $plano->update(['cnc_saida' => $cncPlano, 'dxf_saida' => $dxfPlano, 'erro_mensagem' => null]);
                } catch (Throwable $error) {
                    Storage::disk('local')->delete([$cncPlano, $dxfPlano]);
                    $plano->update(['erro_mensagem' => mb_substr($error->getMessage(), 0, 2000)]);
                    throw $error;
                }
            }
            $pedido->update(['erro_mensagem' => null]);
        } catch (Throwable $error) {
            if (! $pedido->cnc_saida) {
                Storage::disk('local')->delete([$relative, $relativeDxf]);
            }
            $message = mb_substr($error->getMessage(), 0, 2000);
            $pedido->update(['erro_mensagem' => $message]);
            Log::error('Falha ao gerar CNC final', ['pedido_id' => $pedido->id, 'erro' => $message]);
        }
    }
}
