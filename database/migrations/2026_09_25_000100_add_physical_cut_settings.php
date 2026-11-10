<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('espessuras_material', function (Blueprint $table): void {
            $table->decimal('espacamento_minimo_mm', 8, 3)->default(0.5);
            $table->decimal('lead_in_mm', 8, 3)->default(2);
        });
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->boolean('usar_tabs')->default(false);
            $table->decimal('tamanho_tab_mm', 8, 3)->default(1);
        });

        // Existing thicknesses receive editable starting estimates, never a
        // silent zero-clearance value. The operator must calibrate these for
        // the actual machine, material and gas before physical cutting.
        foreach (DB::table('espessuras_material')->select('id', 'kerf_mm')->get() as $row) {
            DB::table('espessuras_material')->where('id', $row->id)->update([
                'espacamento_minimo_mm' => round(max(0.5, (float) $row->kerf_mm + 0.5), 3),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('pedidos_corte', fn (Blueprint $table) => $table->dropColumn(['usar_tabs', 'tamanho_tab_mm']));
        Schema::table('espessuras_material', fn (Blueprint $table) => $table->dropColumn(['espacamento_minimo_mm', 'lead_in_mm']));
    }
};
