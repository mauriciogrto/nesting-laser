<?php

namespace App\Services;

use App\Models\EspessuraMaterial;
use InvalidArgumentException;

class CalculadoraTempoCorte
{
    public function calcular(array $pecasPosicionadas, EspessuraMaterial $espessura, array $opcoes = []): int
    {
        $velocidade = (float) $espessura->velocidade_corte_mm_min;
        $deslocamento = (float) config('nesting.deslocamento_mm_min');
        if ($velocidade <= 0 || $deslocamento <= 0) {
            throw new InvalidArgumentException('Velocidades de corte e deslocamento devem ser positivas.');
        }

        $leadIn = (float) ($opcoes['lead_in_mm'] ?? $espessura->lead_in_mm ?? 2);
        $usarTabs = (bool) ($opcoes['usar_tabs'] ?? false);
        $tamanhoTab = (float) ($opcoes['tamanho_tab_mm'] ?? 1);
        if ($leadIn <= 0 || ($usarTabs && $tamanhoTab <= 0)) {
            throw new InvalidArgumentException('Lead-in ou tamanho de tab inválido.');
        }
        $contornos = $this->ordemCorte($pecasPosicionadas);
        $comprimentoCorte = 0.0;
        $distanciaDeslocamento = 0.0;
        $anterior = [0.0, 0.0];
        foreach ($contornos as $contorno) {
            if ($usarTabs && $contorno['tipo'] === 'externo' && $tamanhoTab >= $contorno['perimetro'] / 4) {
                throw new InvalidArgumentException('A tab é grande demais para um dos contornos externos.');
            }
            $comprimentoCorte += $contorno['perimetro'] + $leadIn
                - ($usarTabs && $contorno['tipo'] === 'externo' ? $tamanhoTab : 0);
            $atual = $contorno['inicio'];
            $distanciaDeslocamento += hypot($atual[0] - $anterior[0], $atual[1] - $anterior[1]);
            $anterior = $atual;
        }

        return (int) ceil(
            ($comprimentoCorte / $velocidade) * 60
            + count($contornos) * (float) $espessura->tempo_perfuracao_seg
            + ($distanciaDeslocamento / $deslocamento) * 60,
        );
    }

    public function ordemCorte(array $pecasPosicionadas): array
    {
        $sequencia = [];
        foreach ($pecasPosicionadas as $peca) {
            $aneis = $peca['contornos'] ?? (isset($peca['contorno']) ? [$peca['contorno']] : []);
            if (! $aneis || count($aneis[0]) < 3) {
                throw new InvalidArgumentException('Contorno de peça inválido para estimativa.');
            }
            $angulo = deg2rad((float) $peca['rotacao_graus']);
            $cos = cos($angulo);
            $sin = sin($angulo);
            $rotacionados = array_map(fn (array $anel) => array_map(fn (array $ponto) => [
                (float) $ponto[0] * $cos - (float) $ponto[1] * $sin,
                (float) $ponto[0] * $sin + (float) $ponto[1] * $cos,
            ], $anel), $aneis);
            $minX = min(array_column($rotacionados[0], 0));
            $minY = min(array_column($rotacionados[0], 1));
            $areaExterna = abs($this->areaAssinada($rotacionados[0]));
            foreach ($rotacionados as $indice => $anel) {
                if (count($anel) < 3) {
                    throw new InvalidArgumentException('Contorno de peça inválido para estimativa.');
                }
                $perimetro = 0.0;
                foreach ($anel as $i => $ponto) {
                    $proximo = $anel[($i + 1) % count($anel)];
                    $perimetro += hypot($proximo[0] - $ponto[0], $proximo[1] - $ponto[1]);
                }
                $sequencia[] = ['tipo' => $indice === 0 ? 'externo' : 'furo',
                    'area_externa_mm2' => $areaExterna, 'perimetro' => $perimetro,
                    '_anel' => array_map(fn (array $p) => [$p[0] - $minX + (float) $peca['x'],
                        $p[1] - $minY + (float) $peca['y']], $anel),
                    'inicio' => [$anel[0][0] - $minX + (float) $peca['x'],
                        $anel[0][1] - $minY + (float) $peca['y']]];
            }
        }
        // Match CNC/DXF containment dependencies: finish nested pieces before
        // releasing their host cutout; at equal depth, holes then small/large
        // external releases. Input contours have already passed geometry checks.
        foreach ($sequencia as $indice => $contorno) {
            $depth = 0;
            foreach ($sequencia as $outro => $candidato) {
                if ($outro !== $indice && $this->dentro($contorno['inicio'], $candidato['_anel'])) {
                    $depth++;
                }
            }
            $sequencia[$indice]['_depth'] = $depth;
        }
        usort($sequencia, fn (array $a, array $b) => $b['_depth'] <=> $a['_depth']
            ?: ($a['tipo'] === 'externo') <=> ($b['tipo'] === 'externo')
            ?: ($a['tipo'] === 'externo' ? $a['area_externa_mm2'] <=> $b['area_externa_mm2'] : 0));
        foreach ($sequencia as &$contorno) {
            unset($contorno['_depth'], $contorno['_anel']);
        }
        unset($contorno);

        return $sequencia;
    }

    private function dentro(array $ponto, array $anel): bool
    {
        $dentro = false;
        for ($i = 0, $j = count($anel) - 1; $i < count($anel); $j = $i++) {
            [$ax, $ay] = $anel[$i];
            [$bx, $by] = $anel[$j];
            if (($ay > $ponto[1]) !== ($by > $ponto[1])
                && $ponto[0] < ($bx - $ax) * ($ponto[1] - $ay) / ($by - $ay) + $ax) {
                $dentro = ! $dentro;
            }
        }

        return $dentro;
    }

    private function areaAssinada(array $anel): float
    {
        $dobro = 0.0;
        foreach ($anel as $indice => $ponto) {
            $proximo = $anel[($indice + 1) % count($anel)];
            $dobro += $ponto[0] * $proximo[1] - $proximo[0] * $ponto[1];
        }

        return $dobro / 2;
    }
}
