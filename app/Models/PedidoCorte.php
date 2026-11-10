<?php

namespace App\Models;

use App\Enums\StatusPedido;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PedidoCorte extends Model
{
    use HasFactory;

    protected $table = 'pedidos_corte';

    protected $fillable = [
        'nome', 'status', 'chapa_id', 'resultado_nesting_json', 'dxf_saida', 'cnc_saida',
        'tempo_corte_estimado_seg', 'tempo_corte_real_seg', 'sobra_aproveitavel_json', 'erro_mensagem',
        'concluido_em',
        'area_chapa_mm2', 'area_pecas_mm2',
        'data_prevista', 'data_entrega', 'exportado_em', 'estoque_baixado_em', 'retalho_chapa_id',
        'custo_material', 'custo_maquina', 'custo_total',
        'usar_tabs', 'tamanho_tab_mm',
    ];

    protected function casts(): array
    {
        return [
            'status' => StatusPedido::class,
            'resultado_nesting_json' => 'array', 'sobra_aproveitavel_json' => 'array',
            'tempo_corte_estimado_seg' => 'integer', 'tempo_corte_real_seg' => 'integer',
            'concluido_em' => 'datetime',
            'data_prevista' => 'date', 'data_entrega' => 'date',
            'exportado_em' => 'datetime', 'estoque_baixado_em' => 'datetime',
            'custo_material' => 'decimal:2', 'custo_maquina' => 'decimal:2', 'custo_total' => 'decimal:2',
            'usar_tabs' => 'boolean', 'tamanho_tab_mm' => 'decimal:3',
        ];
    }

    public function chapa(): BelongsTo
    {
        return $this->belongsTo(Chapa::class);
    }

    public function retalho(): BelongsTo
    {
        return $this->belongsTo(Chapa::class, 'retalho_chapa_id');
    }

    public function pecas(): BelongsToMany
    {
        return $this->belongsToMany(Peca::class, 'pedido_corte_pecas')
            ->withPivot(['id', 'quantidade']);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoEstoque::class);
    }

    public function chapasInternas(): HasMany
    {
        return $this->hasMany(PedidoChapa::class, 'pedido_corte_id')->orderBy('numero');
    }
}
