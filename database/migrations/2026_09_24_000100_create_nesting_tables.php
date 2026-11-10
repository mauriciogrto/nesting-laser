<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('materiais', function (Blueprint $table): void {
            $table->id();
            $table->string('nome')->unique();
            $table->timestamps();
        });

        Schema::create('espessuras_material', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_id')->constrained('materiais')->restrictOnDelete();
            $table->decimal('valor_mm', 8, 3);
            $table->decimal('velocidade_corte_mm_min', 10, 2);
            $table->decimal('tempo_perfuracao_seg', 8, 3);
            $table->timestamps();
            $table->unique(['material_id', 'valor_mm']);
        });

        Schema::create('chapas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('material_id')->constrained('materiais')->restrictOnDelete();
            $table->foreignId('espessura_id')->constrained('espessuras_material')->restrictOnDelete();
            $table->decimal('largura_mm', 12, 3);
            $table->decimal('altura_mm', 12, 3);
            $table->enum('origem', ['nova', 'retalho'])->default('nova');
            $table->foreignId('chapa_pai_id')->nullable()->constrained('chapas')->restrictOnDelete();
            $table->json('contorno_json')->nullable();
            $table->unsignedInteger('quantidade_disponivel')->default(1);
            $table->enum('status', ['disponivel', 'reservada', 'baixada'])->default('disponivel');
            $table->timestamps();
            $table->index(['material_id', 'espessura_id', 'status']);
        });

        Schema::create('pecas', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->string('arquivo_dxf_original');
            $table->json('contorno_json')->nullable();
            $table->decimal('largura_bbox_mm', 12, 3)->nullable();
            $table->decimal('altura_bbox_mm', 12, 3)->nullable();
            $table->timestamps();
        });

        Schema::create('pedidos_corte', function (Blueprint $table): void {
            $table->id();
            $table->enum('status', ['rascunho', 'aguardando_nesting', 'pronto', 'em_producao', 'concluido'])->default('rascunho');
            $table->foreignId('chapa_id')->nullable()->constrained('chapas')->restrictOnDelete();
            $table->json('resultado_nesting_json')->nullable();
            $table->string('dxf_saida')->nullable();
            $table->unsignedInteger('tempo_corte_estimado_seg')->nullable();
            $table->unsignedInteger('tempo_corte_real_seg')->nullable();
            $table->json('sobra_aproveitavel_json')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('pedido_corte_pecas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pedido_corte_id')->constrained('pedidos_corte')->cascadeOnDelete();
            $table->foreignId('peca_id')->constrained('pecas')->restrictOnDelete();
            $table->unsignedInteger('quantidade');
            $table->unique(['pedido_corte_id', 'peca_id']);
        });

        Schema::create('movimentacoes_estoque', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chapa_id')->constrained('chapas')->restrictOnDelete();
            $table->enum('tipo', ['entrada', 'baixa', 'geracao_retalho']);
            $table->foreignId('pedido_corte_id')->nullable()->constrained('pedidos_corte')->nullOnDelete();
            $table->unsignedInteger('quantidade');
            $table->string('observacao')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tipo', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['movimentacoes_estoque', 'pedido_corte_pecas', 'pedidos_corte', 'pecas', 'chapas', 'espessuras_material', 'materiais'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
