<?php

namespace App\Models;

use App\Enums\OrigemChapa;
use App\Enums\StatusChapa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chapa extends Model
{
    use HasFactory;

    protected $table = 'chapas';

    protected $fillable = [
        'material_id', 'espessura_id', 'largura_mm', 'altura_mm', 'origem',
        'chapa_pai_id', 'contorno_json', 'quantidade_disponivel', 'status', 'custo_unitario',
    ];

    protected function casts(): array
    {
        return [
            'largura_mm' => 'decimal:3', 'altura_mm' => 'decimal:3',
            'origem' => OrigemChapa::class, 'status' => StatusChapa::class,
            'contorno_json' => 'array', 'quantidade_disponivel' => 'integer', 'custo_unitario' => 'decimal:2',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function espessura(): BelongsTo
    {
        return $this->belongsTo(EspessuraMaterial::class, 'espessura_id');
    }

    public function chapaPai(): BelongsTo
    {
        return $this->belongsTo(self::class, 'chapa_pai_id');
    }

    public function retalhos(): HasMany
    {
        return $this->hasMany(self::class, 'chapa_pai_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(PedidoCorte::class);
    }

    public function movimentacoes(): HasMany
    {
        return $this->hasMany(MovimentacaoEstoque::class);
    }
}
