<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EspessuraMaterial extends Model
{
    use HasFactory;

    protected $table = 'espessuras_material';

    protected $fillable = ['material_id', 'valor_mm', 'velocidade_corte_mm_min', 'tempo_perfuracao_seg', 'kerf_mm', 'espacamento_minimo_mm', 'lead_in_mm', 'custo_hora_maquina'];

    protected function casts(): array
    {
        return [
            'valor_mm' => 'decimal:3',
            'velocidade_corte_mm_min' => 'decimal:2',
            'tempo_perfuracao_seg' => 'decimal:3',
            'kerf_mm' => 'decimal:3',
            'espacamento_minimo_mm' => 'decimal:3',
            'lead_in_mm' => 'decimal:3',
            'custo_hora_maquina' => 'decimal:2',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function chapas(): HasMany
    {
        return $this->hasMany(Chapa::class, 'espessura_id');
    }
}
