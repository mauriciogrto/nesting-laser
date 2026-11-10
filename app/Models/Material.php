<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    use HasFactory;

    protected $table = 'materiais';

    protected $fillable = ['nome', 'estoque_minimo_alerta', 'densidade_kg_m3'];

    protected function casts(): array
    {
        return ['densidade_kg_m3' => 'decimal:2'];
    }

    public function espessuras(): HasMany
    {
        return $this->hasMany(EspessuraMaterial::class);
    }

    public function chapas(): HasMany
    {
        return $this->hasMany(Chapa::class);
    }
}
