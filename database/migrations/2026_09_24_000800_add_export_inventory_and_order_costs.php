<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapas', function (Blueprint $table): void {
            $table->decimal('custo_unitario', 12, 2)->default(0);
        });
        Schema::table('espessuras_material', function (Blueprint $table): void {
            $table->decimal('custo_hora_maquina', 10, 2)->default(0);
        });
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->date('data_prevista')->nullable();
            $table->date('data_entrega')->nullable();
            $table->timestamp('exportado_em')->nullable();
            $table->timestamp('estoque_baixado_em')->nullable();
            $table->foreignId('retalho_chapa_id')->nullable()->constrained('chapas')->nullOnDelete();
            $table->decimal('custo_material', 12, 2)->default(0);
            $table->decimal('custo_maquina', 12, 2)->default(0);
            $table->decimal('custo_total', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('retalho_chapa_id');
            $table->dropColumn(['data_prevista', 'data_entrega', 'exportado_em', 'estoque_baixado_em',
                'custo_material', 'custo_maquina', 'custo_total']);
        });
        Schema::table('espessuras_material', fn (Blueprint $table) => $table->dropColumn('custo_hora_maquina'));
        Schema::table('chapas', fn (Blueprint $table) => $table->dropColumn('custo_unitario'));
    }
};
