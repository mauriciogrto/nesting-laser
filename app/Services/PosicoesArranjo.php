<?php

namespace App\Services;

use App\Models\Chapa;
use Illuminate\Validation\ValidationException;

class PosicoesArranjo
{
    public function validar(array $inputs, array $originais, Chapa $chapa, GeometriaNesting $geometria): array
    {
        $porInstancia = collect($originais)->keyBy('instancia');
        if (count($inputs) !== $porInstancia->count()) {
            throw ValidationException::withMessages(['posicoes' => 'Envie todas as peças desta chapa.']);
        }
        $vistos = [];
        $posicoes = [];
        foreach ($inputs as $input) {
            $instancia = (int) $input['instancia'];
            $base = $porInstancia->get($instancia);
            if (! $base || isset($vistos[$instancia]) || (string) $base['id'] !== (string) $input['id']) {
                throw ValidationException::withMessages(['posicoes' => 'ID ou instância de peça inválido.']);
            }
            $vistos[$instancia] = true;
            $item = $base;
            $item['x'] = (float) $input['x'];
            $item['y'] = (float) $input['y'];
            $item['rotacao_graus'] = fmod((float) $input['rotacao_graus'] + 3600, 360);
            if (! is_finite($item['x']) || ! is_finite($item['y']) || ! $geometria->cabeNaChapa($item, $chapa)) {
                throw ValidationException::withMessages(['posicoes' => "A peça {$instancia} está fora da chapa."]);
            }
            $posicoes[] = $item;
        }

        return $posicoes;
    }
}
