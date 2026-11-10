<?php

namespace App\Models;

use App\Enums\TipoMovimentacao;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimentacaoEstoque extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $table = 'movimentacoes_estoque';

    protected $fillable = ['chapa_id', 'tipo', 'pedido_corte_id', 'quantidade', 'observacao'];

    protected function casts(): array
    {
        return ['tipo' => TipoMovimentacao::class, 'quantidade' => 'integer'];
    }

    public function chapa(): BelongsTo
    {
        return $this->belongsTo(Chapa::class);
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(PedidoCorte::class, 'pedido_corte_id');
    }
}
