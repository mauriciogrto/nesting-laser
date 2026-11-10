<?php

namespace App\Services;

class AreaGeometria
{
    public function contorno(array $points): float
    {
        $area = 0;
        foreach ($points as $index => $point) {
            $next = $points[($index + 1) % count($points)];
            $area += $point[0] * $next[1] - $next[0] * $point[1];
        }

        return abs($area) / 2;
    }

    public function chapa(array $contorno, float $largura, float $altura): float
    {
        return $contorno ? $this->contorno($contorno) : $largura * $altura;
    }

    public function pecas(array $items): float
    {
        $total = 0;
        foreach ($items as $item) {
            $contornos = $item['contornos'] ?? [];
            if (! $contornos) {
                continue;
            }
            $total += $this->contorno($contornos[0]);
            foreach (array_slice($contornos, 1) as $hole) {
                $total -= $this->contorno($hole);
            }
        }

        return $total;
    }
}
