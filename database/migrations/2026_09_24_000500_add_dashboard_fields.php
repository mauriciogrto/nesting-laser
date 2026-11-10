<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materiais', fn (Blueprint $table) => $table->unsignedInteger('estoque_minimo_alerta')->default(1));
        Schema::table('pedidos_corte', fn (Blueprint $table) => $table->timestamp('concluido_em')->nullable()->index());
        DB::table('pedidos_corte')->where('status', 'concluido')->update(['concluido_em' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('pedidos_corte', fn (Blueprint $table) => $table->dropColumn('concluido_em'));
        Schema::table('materiais', fn (Blueprint $table) => $table->dropColumn('estoque_minimo_alerta'));
    }
};
