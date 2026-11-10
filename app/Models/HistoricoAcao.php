<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricoAcao extends Model
{
    const UPDATED_AT = null;

    protected $table = 'historico_acoes';

    protected $fillable = ['user_id', 'acao', 'entidade', 'entidade_id', 'dados_alterados'];

    protected function casts(): array
    {
        return ['dados_alterados' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
