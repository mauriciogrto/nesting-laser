<?php

namespace App\Services;

use App\Models\Chapa;

class GeometriaNesting
{
    public function contornoPosicionado(array $peca): array
    {
        $radians = deg2rad((float) $peca['rotacao_graus']);
        $cos = cos($radians);
        $sin = sin($radians);
        $rotated = array_map(
            fn ($point) => [
                (float) $point[0] * $cos - (float) $point[1] * $sin,
                (float) $point[0] * $sin + (float) $point[1] * $cos,
            ],
            $peca['contornos'][0],
        );
        $minX = min(array_column($rotated, 0));
        $minY = min(array_column($rotated, 1));

        return array_map(
            fn ($point) => [$point[0] - $minX + (float) $peca['x'], $point[1] - $minY + (float) $peca['y']],
            $rotated,
        );
    }

    public function cabeNaChapa(array $peca, Chapa $chapa): bool
    {
        $sheet = $chapa->contorno_json ?: [
            [0, 0], [(float) $chapa->largura_mm, 0],
            [(float) $chapa->largura_mm, (float) $chapa->altura_mm], [0, (float) $chapa->altura_mm],
        ];
        $part = $this->contornoPosicionado($peca);
        foreach ($part as $point) {
            if (! $this->pointInside($point, $sheet)) {
                return false;
            }
        }
        for ($i = 0; $i < count($part); $i++) {
            $next = $part[($i + 1) % count($part)];
            // Catches a segment leaving a concave sheet although both ends lie inside.
            $midpoint = [($part[$i][0] + $next[0]) / 2, ($part[$i][1] + $next[1]) / 2];
            if (! $this->pointInside($midpoint, $sheet)) {
                return false;
            }
            for ($j = 0; $j < count($sheet); $j++) {
                if ($this->properIntersection($part[$i], $next, $sheet[$j], $sheet[($j + 1) % count($sheet)])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function pointInside(array $point, array $polygon): bool
    {
        $inside = false;
        for ($i = 0, $j = count($polygon) - 1; $i < count($polygon); $j = $i++) {
            $a = $polygon[$j];
            $b = $polygon[$i];
            $cross = ($point[0] - $a[0]) * ($b[1] - $a[1]) - ($point[1] - $a[1]) * ($b[0] - $a[0]);
            if (abs($cross) < 1e-7 && $point[0] >= min($a[0], $b[0]) - 1e-7 && $point[0] <= max($a[0], $b[0]) + 1e-7 && $point[1] >= min($a[1], $b[1]) - 1e-7 && $point[1] <= max($a[1], $b[1]) + 1e-7) {
                return true;
            }
            if (($a[1] > $point[1]) !== ($b[1] > $point[1]) && $point[0] < ($b[0] - $a[0]) * ($point[1] - $a[1]) / ($b[1] - $a[1]) + $a[0]) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private function properIntersection(array $a, array $b, array $c, array $d): bool
    {
        $orient = fn ($p, $q, $r) => ($q[0] - $p[0]) * ($r[1] - $p[1]) - ($q[1] - $p[1]) * ($r[0] - $p[0]);
        $abC = $orient($a, $b, $c);
        $abD = $orient($a, $b, $d);
        $cdA = $orient($c, $d, $a);
        $cdB = $orient($c, $d, $b);

        return $abC * $abD < -1e-10 && $cdA * $cdB < -1e-10;
    }
}
