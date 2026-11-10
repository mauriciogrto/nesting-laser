<?php

namespace App\Models;

use App\Enums\StatusProcessamento;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Peca extends Model
{
    use HasFactory;

    protected $table = 'pecas';

    protected $fillable = ['nome', 'arquivo_dxf_original', 'contorno_json', 'largura_bbox_mm', 'altura_bbox_mm', 'status_processamento', 'erro_mensagem'];

    protected function casts(): array
    {
        return [
            'contorno_json' => 'array',
            'status_processamento' => StatusProcessamento::class,
            'largura_bbox_mm' => 'decimal:3', 'altura_bbox_mm' => 'decimal:3',
        ];
    }

    public function pedidos(): BelongsToMany
    {
        return $this->belongsToMany(PedidoCorte::class, 'pedido_corte_pecas')
            ->withPivot(['id', 'quantidade']);
    }
}
