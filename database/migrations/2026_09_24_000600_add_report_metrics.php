<?php

use App\Services\AreaGeometria;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->decimal('area_chapa_mm2', 18, 3)->nullable();
            $table->decimal('area_pecas_mm2', 18, 3)->nullable();
        });
        $area = new AreaGeometria;
        DB::table('pedidos_corte')->whereNotNull('resultado_nesting_json')->orderBy('id')->chunkById(100, function ($pedidos) use ($area): void {
            foreach ($pedidos as $pedido) {
                $chapa = DB::table('chapas')->where('id', $pedido->chapa_id)->first();
                if (! $chapa) {
                    continue;
                }
                $result = json_decode($pedido->resultado_nesting_json, true);
                if (! is_array($result['pecas_posicionadas'] ?? null)) {
                    continue;
                }
                DB::table('pedidos_corte')->where('id', $pedido->id)->update([
                    'area_chapa_mm2' => $area->chapa(json_decode($chapa->contorno_json ?? 'null', true) ?: [], (float) $chapa->largura_mm, (float) $chapa->altura_mm),
                    'area_pecas_mm2' => $area->pecas($result['pecas_posicionadas']),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_corte', fn (Blueprint $table) => $table->dropColumn(['area_chapa_mm2', 'area_pecas_mm2']));
    }
};
