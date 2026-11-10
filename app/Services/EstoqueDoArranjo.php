<?php

namespace App\Services;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use App\Enums\StatusPedido;
use App\Enums\TipoMovimentacao;
use App\Models\Chapa;
use App\Models\MovimentacaoEstoque;
use App\Models\PedidoChapa;
use App\Models\PedidoCorte;
use Illuminate\Validation\ValidationException;

class EstoqueDoArranjo
{
    public function __construct(private AreaGeometria $area) {}

    public function reverterAoExcluir(PedidoCorte $pedido): void
    {
        if (in_array($pedido->status, [StatusPedido::EmProducao, StatusPedido::Concluido], true)) {
            throw ValidationException::withMessages(['pedido' => 'Pedidos em produção ou concluídos não podem ser excluídos.']);
        }
        if ($pedido->status === StatusPedido::AguardandoNesting) {
            throw ValidationException::withMessages(['pedido' => 'Aguarde o fim da otimização para excluir este pedido.']);
        }
        if (! $pedido->estoque_baixado_em) {
            return;
        }

        foreach ($pedido->chapasInternas as $plano) {
            if (! $plano->estoque_baixado_em) {
                continue;
            }
            $this->reverterChapaInterna($pedido, $plano);
        }

        $chapa = Chapa::whereKey($pedido->chapa_id)->lockForUpdate()->firstOrFail();
        $retalho = $pedido->retalho_chapa_id
            ? Chapa::whereKey($pedido->retalho_chapa_id)->lockForUpdate()->firstOrFail()
            : null;
        if ($retalho && ($retalho->quantidade_disponivel > 1 ||
            $retalho->pedidos()->whereKeyNot($pedido->id)->exists() ||
            PedidoChapa::where('chapa_id', $retalho->id)->exists() || $retalho->retalhos()->exists() ||
            $retalho->movimentacoes()->where(fn ($query) => $query->whereNull('pedido_corte_id')
                ->orWhere('pedido_corte_id', '!=', $pedido->id))->exists())) {
            throw ValidationException::withMessages(['pedido' => 'O retalho deste pedido já foi utilizado. A exclusão foi bloqueada para proteger o estoque.']);
        }
        if ($retalho && $retalho->quantidade_disponivel > 0) {
            $retalho->update(['quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
            MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::Baixa,
                'pedido_corte_id' => $pedido->id, 'quantidade' => 1, 'observacao' => 'Retalho cancelado pela exclusão do pedido']);
        }
        $chapa->update(['quantidade_disponivel' => $chapa->quantidade_disponivel + 1,
            'status' => StatusChapa::Disponivel]);
        MovimentacaoEstoque::create(['chapa_id' => $chapa->id, 'tipo' => TipoMovimentacao::Entrada,
            'pedido_corte_id' => $pedido->id, 'quantidade' => 1, 'observacao' => 'Chapa devolvida pela exclusão do pedido']);
    }

    public function baixarChapaInterna(PedidoCorte $pedido, PedidoChapa $plano, Chapa $chapa): void
    {
        if ($plano->estoque_baixado_em) {
            return;
        }
        if ($chapa->status !== StatusChapa::Disponivel || $chapa->quantidade_disponivel < 1) {
            throw ValidationException::withMessages(['chapa' => 'A chapa não está mais disponível para exportação.']);
        }
        $restante = $chapa->quantidade_disponivel - 1;
        $chapa->update(['quantidade_disponivel' => $restante,
            'status' => $restante ? StatusChapa::Disponivel : StatusChapa::Baixada]);
        MovimentacaoEstoque::create(['chapa_id' => $chapa->id, 'tipo' => TipoMovimentacao::Baixa,
            'pedido_corte_id' => $pedido->id, 'quantidade' => 1,
            'observacao' => 'Chapa '.$plano->numero.' usada no pedido']);
        $retalhoDados = $this->dadosRetalho($plano->sobra_aproveitavel_json, $chapa);
        $retalho = null;
        if ($retalhoDados) {
            $retalho = Chapa::create(['material_id' => $chapa->material_id,
                'espessura_id' => $chapa->espessura_id, 'origem' => OrigemChapa::Retalho,
                'chapa_pai_id' => $chapa->id, ...$retalhoDados,
                'quantidade_disponivel' => 1, 'status' => StatusChapa::Disponivel]);
            MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::GeracaoRetalho,
                'pedido_corte_id' => $pedido->id, 'quantidade' => 1,
                'observacao' => 'Retalho da chapa '.$plano->numero.' do pedido #'.$pedido->id]);
        }
        $plano->update(['estoque_baixado_em' => now(), 'retalho_chapa_id' => $retalho?->id,
            'custo_material' => max(0, round((float) $chapa->custo_unitario - (float) ($retalhoDados['custo_unitario'] ?? 0), 2)),
            'custo_maquina' => round((float) $chapa->espessura->custo_hora_maquina * $plano->tempo_corte_estimado_seg / 3600, 2)]);
    }

    public function sincronizarChapaInterna(PedidoCorte $pedido, PedidoChapa $plano, Chapa $chapa): void
    {
        $retalhoDados = $this->dadosRetalho($plano->sobra_aproveitavel_json, $chapa);
        $retalho = $plano->retalho_chapa_id
            ? Chapa::whereKey($plano->retalho_chapa_id)->lockForUpdate()->firstOrFail() : null;
        if ($retalho && ($retalho->pedidos()->exists() || $retalho->retalhos()->exists() ||
            PedidoChapa::where('chapa_id', $retalho->id)->exists() ||
            $retalho->movimentacoes()->where(fn ($query) => $query->whereNull('pedido_corte_id')
                ->orWhere('pedido_corte_id', '!=', $pedido->id))->exists())) {
            throw ValidationException::withMessages(['arranjo' => 'O retalho desta chapa já foi utilizado.']);
        }
        if ($retalhoDados) {
            if ($retalho) {
                $retalho->update([...$retalhoDados, 'quantidade_disponivel' => 1, 'status' => StatusChapa::Disponivel]);
            } else {
                $retalho = Chapa::create(['material_id' => $chapa->material_id,
                    'espessura_id' => $chapa->espessura_id, 'origem' => OrigemChapa::Retalho,
                    'chapa_pai_id' => $chapa->id, ...$retalhoDados,
                    'quantidade_disponivel' => 1, 'status' => StatusChapa::Disponivel]);
                MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::GeracaoRetalho,
                    'pedido_corte_id' => $pedido->id, 'quantidade' => 1,
                    'observacao' => 'Retalho da chapa '.$plano->numero.' do pedido #'.$pedido->id]);
            }
        } elseif ($retalho && $retalho->quantidade_disponivel > 0) {
            $retalho->update(['quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
            MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::Baixa,
                'pedido_corte_id' => $pedido->id, 'quantidade' => 1,
                'observacao' => 'Retalho removido após edição do arranjo']);
        }
        $plano->update(['retalho_chapa_id' => $retalho?->id,
            'custo_material' => max(0, round((float) $chapa->custo_unitario - (float) ($retalhoDados['custo_unitario'] ?? 0), 2)),
            'custo_maquina' => round((float) $chapa->espessura->custo_hora_maquina * $plano->tempo_corte_estimado_seg / 3600, 2)]);
        $this->atualizarTotaisLote($pedido);
    }

    private function reverterChapaInterna(PedidoCorte $pedido, PedidoChapa $plano): void
    {
        $chapa = Chapa::whereKey($plano->chapa_id)->lockForUpdate()->firstOrFail();
        $retalho = $plano->retalho_chapa_id
            ? Chapa::whereKey($plano->retalho_chapa_id)->lockForUpdate()->firstOrFail() : null;
        if ($retalho && ($retalho->quantidade_disponivel > 1 || $retalho->pedidos()->exists()
            || PedidoChapa::where('chapa_id', $retalho->id)->exists()
            || $retalho->retalhos()->exists() || $retalho->movimentacoes()->where(fn ($query) => $query->whereNull('pedido_corte_id')->orWhere('pedido_corte_id', '!=', $pedido->id))->exists())) {
            throw ValidationException::withMessages(['pedido' => 'O retalho de uma chapa deste pedido já foi utilizado.']);
        }
        if ($retalho && $retalho->quantidade_disponivel > 0) {
            $retalho->update(['quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
            MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::Baixa,
                'pedido_corte_id' => $pedido->id, 'quantidade' => 1,
                'observacao' => 'Retalho cancelado pela exclusão do pedido']);
        }
        $chapa->update(['quantidade_disponivel' => $chapa->quantidade_disponivel + 1,
            'status' => StatusChapa::Disponivel]);
        MovimentacaoEstoque::create(['chapa_id' => $chapa->id, 'tipo' => TipoMovimentacao::Entrada,
            'pedido_corte_id' => $pedido->id, 'quantidade' => 1,
            'observacao' => 'Chapa '.$plano->numero.' devolvida pela exclusão do pedido']);
    }

    public function baixarNaExportacao(PedidoCorte $pedido, Chapa $chapa): void
    {
        if ($pedido->estoque_baixado_em) {
            return;
        }
        if ($chapa->status !== StatusChapa::Disponivel || $chapa->quantidade_disponivel < 1) {
            throw ValidationException::withMessages(['chapa' => 'A chapa não está mais disponível para exportação.']);
        }
        $restante = $chapa->quantidade_disponivel - 1;
        $chapa->update(['quantidade_disponivel' => $restante,
            'status' => $restante ? StatusChapa::Disponivel : StatusChapa::Baixada]);
        MovimentacaoEstoque::create(['chapa_id' => $chapa->id, 'tipo' => TipoMovimentacao::Baixa,
            'pedido_corte_id' => $pedido->id, 'quantidade' => 1, 'observacao' => 'Chapa usada na exportação do arranjo']);
        $pedido->update(['exportado_em' => now(), 'estoque_baixado_em' => now()]);
        $this->sincronizarRetalhoECustos($pedido, $chapa);
    }

    public function sincronizarRetalhoECustos(PedidoCorte $pedido, Chapa $chapa): void
    {
        $chapa->loadMissing('espessura');
        $contorno = $pedido->sobra_aproveitavel_json;
        $retalhoDados = $this->dadosRetalho($contorno, $chapa);
        $retalho = $pedido->retalho_chapa_id
            ? Chapa::whereKey($pedido->retalho_chapa_id)->lockForUpdate()->firstOrFail()
            : null;
        if ($retalho && ($retalho->pedidos()->exists() ||
            PedidoChapa::where('chapa_id', $retalho->id)->exists() || $retalho->retalhos()->exists())) {
            throw ValidationException::withMessages(['arranjo' => 'O retalho deste pedido já foi utilizado. O arranjo não pode mais ser alterado.']);
        }
        if ($retalhoDados) {
            if ($retalho) {
                $reativado = $retalho->quantidade_disponivel === 0;
                $retalho->update([...$retalhoDados, 'quantidade_disponivel' => 1, 'status' => StatusChapa::Disponivel]);
                if ($reativado) {
                    MovimentacaoEstoque::create([
                        'chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::Entrada,
                        'pedido_corte_id' => $pedido->id, 'quantidade' => 1,
                        'observacao' => 'Retalho atualizado após edição do arranjo',
                    ]);
                }
            } else {
                $retalho = Chapa::create([
                    'material_id' => $chapa->material_id, 'espessura_id' => $chapa->espessura_id,
                    'origem' => OrigemChapa::Retalho, 'chapa_pai_id' => $chapa->id,
                    ...$retalhoDados, 'quantidade_disponivel' => 1, 'status' => StatusChapa::Disponivel,
                ]);
                MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::GeracaoRetalho,
                    'pedido_corte_id' => $pedido->id, 'quantidade' => 1, 'observacao' => 'Retalho do pedido #'.$pedido->id]);
                $pedido->retalho_chapa_id = $retalho->id;
            }
        } elseif ($retalho && $retalho->quantidade_disponivel > 0) {
            $retalho->update(['quantidade_disponivel' => 0, 'status' => StatusChapa::Baixada]);
            MovimentacaoEstoque::create(['chapa_id' => $retalho->id, 'tipo' => TipoMovimentacao::Baixa,
                'pedido_corte_id' => $pedido->id, 'quantidade' => 1, 'observacao' => 'Retalho removido após edição do arranjo']);
        }
        $custoChapa = (float) $chapa->custo_unitario;
        $custoRetalho = $retalhoDados ? (float) $retalhoDados['custo_unitario'] : 0;
        $custoMaterial = max(0, round($custoChapa - $custoRetalho, 2));
        $pedido->update(['retalho_chapa_id' => $pedido->retalho_chapa_id,
            'custo_material' => $custoMaterial + $pedido->chapasInternas()->sum('custo_material')]);
        $this->atualizarCustoTempo($pedido, $chapa);
    }

    public function atualizarCustoTempo(PedidoCorte $pedido, Chapa $chapa): void
    {
        $chapa->loadMissing('espessura');
        $segundos = $pedido->tempo_corte_real_seg ?? $pedido->tempo_corte_estimado_seg ?? 0;
        $custoMaquina = round((float) $chapa->espessura->custo_hora_maquina * (int) $segundos / 3600, 2);
        $pedido->update(['custo_maquina' => $custoMaquina,
            'custo_total' => round((float) $pedido->custo_material + $custoMaquina, 2)]);
    }

    public function atualizarTotaisLote(PedidoCorte $pedido): void
    {
        $chapa = $pedido->chapa->loadMissing('espessura');
        $retalho = $pedido->retalho;
        $materialPrimeira = max(0, (float) $chapa->custo_unitario - (float) ($retalho?->custo_unitario ?? 0));
        $pedido->update(['custo_material' => round($materialPrimeira + $pedido->chapasInternas()->sum('custo_material'), 2)]);
        $this->atualizarCustoTempo($pedido, $chapa);
    }

    private function dadosRetalho(?array $contorno, Chapa $chapa): ?array
    {
        if (! $contorno || count($contorno) < 3) {
            return null;
        }
        $xs = array_column($contorno, 0);
        $ys = array_column($contorno, 1);
        $minX = min($xs);
        $minY = min($ys);
        $largura = max($xs) - $minX;
        $altura = max($ys) - $minY;
        if ($largura <= 0 || $altura <= 0) {
            return null;
        }
        $areaChapa = $this->area->chapa($chapa->contorno_json ?? [], (float) $chapa->largura_mm, (float) $chapa->altura_mm);
        $areaRetalho = $this->area->contorno($contorno);
        if ($areaChapa <= 0 || $areaRetalho <= 0) {
            return null;
        }
        // Sheet dimensions are stored to 0.001 mm. Keep its contour on the
        // same grid so a rounded dimension cannot fall just inside the edge.
        $normalizado = array_map(fn ($p) => [round($p[0] - $minX, 3), round($p[1] - $minY, 3)], $contorno);
        $larguraGravada = round(max(array_column($normalizado, 0)), 3);
        $alturaGravada = round(max(array_column($normalizado, 1)), 3);
        if ($larguraGravada <= 0 || $alturaGravada <= 0) {
            return null;
        }

        return ['largura_mm' => $larguraGravada,
            'altura_mm' => $alturaGravada,
            'contorno_json' => $normalizado,
            'custo_unitario' => round((float) $chapa->custo_unitario * min(1, $areaRetalho / $areaChapa), 2)];
    }
}
