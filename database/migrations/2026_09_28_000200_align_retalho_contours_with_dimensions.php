<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('chapas')->where('origem', 'retalho')
            ->whereNotNull('contorno_json')->get(['id', 'largura_mm', 'altura_mm', 'contorno_json']) as $chapa) {
            $contorno = json_decode($chapa->contorno_json, true);
            if (! is_array($contorno) || count($contorno) < 3) {
                continue;
            }
            $origXs = array_column($contorno, 0);
            $origYs = array_column($contorno, 1);
            if (count($origXs) !== count($contorno) || count($origYs) !== count($contorno)) {
                continue;
            }
            if (min($origXs) >= -0.00001 && min($origYs) >= -0.00001 &&
                max($origXs) <= (float) $chapa->largura_mm + 0.00001 &&
                max($origYs) <= (float) $chapa->altura_mm + 0.00001) {
                continue;
            }
            $normalizado = [];
            foreach ($contorno as $ponto) {
                if (! is_array($ponto) || count($ponto) !== 2 ||
                    ! is_numeric($ponto[0]) || ! is_numeric($ponto[1])) {
                    continue 2;
                }
                $normalizado[] = [round((float) $ponto[0], 3), round((float) $ponto[1], 3)];
            }
            $xs = array_column($normalizado, 0);
            $ys = array_column($normalizado, 1);
            if (min($xs) < 0 || min($ys) < 0 ||
                abs(max($xs) - (float) $chapa->largura_mm) > 0.0005 ||
                abs(max($ys) - (float) $chapa->altura_mm) > 0.0005) {
                continue;
            }
            if ($normalizado !== $contorno) {
                DB::table('chapas')->where('id', $chapa->id)->update([
                    'contorno_json' => json_encode($normalizado, JSON_THROW_ON_ERROR),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Precision repair cannot reconstruct the original excess fractions.
    }
};
