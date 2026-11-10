<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->enum('status', ['rascunho', 'aguardando_nesting', 'pronto', 'em_producao', 'concluido', 'erro'])->default('rascunho')->change();
            $table->text('erro_mensagem')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->dropColumn('erro_mensagem');
            $table->enum('status', ['rascunho', 'aguardando_nesting', 'pronto', 'em_producao', 'concluido'])->default('rascunho')->change();
        });
    }
};
