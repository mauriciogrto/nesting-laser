<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pecas', function (Blueprint $table): void {
            $table->enum('status_processamento', ['pendente', 'processado', 'erro'])->default('pendente');
            $table->text('erro_mensagem')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pecas', function (Blueprint $table): void {
            $table->dropColumn(['status_processamento', 'erro_mensagem']);
        });
    }
};
