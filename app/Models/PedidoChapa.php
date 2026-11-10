<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PedidoChapa extends Model
{
    protected $table = 'pedido_chapas';

    protected $fillable = ['pedido_corte_id', 'numero', 'chapa_id', 'retalho_chapa_id',
        'resultado_nesting_json', 'sobra_aproveitavel_json', 'tempo_corte_estimado_seg',
        'area_chapa_mm2', 'area_pecas_mm2', 'custo_material', 'custo_maquina',
        'cnc_saida', 'dxf_saida', 'erro_mensagem', 'estoque_baixado_em'];

    protected function casts(): array
    {
        return ['resultado_nesting_json' => 'array', 'sobra_aproveitavel_json' => 'array',
            'estoque_baixado_em' => 'datetime'];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCorte::class, 'pedido_corte_id');
    }

    public function chapa(): BelongsTo
    {
        return $this->belongsTo(Chapa::class);
    }

    public function retalho(): BelongsTo
    {
        return $this->belongsTo(Chapa::class, 'retalho_chapa_id');
    }
}
