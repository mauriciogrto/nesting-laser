<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_chapas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pedido_corte_id')->constrained('pedidos_corte')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->foreignId('chapa_id')->constrained('chapas')->restrictOnDelete();
            $table->foreignId('retalho_chapa_id')->nullable()->constrained('chapas')->nullOnDelete();
            $table->json('resultado_nesting_json');
            $table->json('sobra_aproveitavel_json')->nullable();
            $table->unsignedInteger('tempo_corte_estimado_seg')->default(0);
            $table->decimal('area_chapa_mm2', 18, 3)->default(0);
            $table->decimal('area_pecas_mm2', 18, 3)->default(0);
            $table->decimal('custo_material', 12, 2)->default(0);
            $table->decimal('custo_maquina', 12, 2)->default(0);
            $table->string('cnc_saida')->nullable();
            $table->string('dxf_saida')->nullable();
            $table->string('erro_mensagem', 2000)->nullable();
            $table->timestamp('estoque_baixado_em')->nullable();
            $table->timestamps();
            $table->unique(['pedido_corte_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_chapas');
    }
};
