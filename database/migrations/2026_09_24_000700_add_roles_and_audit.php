<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('role', ['admin', 'operador'])->default('operador');
            $table->boolean('active')->default(true);
        });
        DB::table('users')->update(['role' => 'admin']);
        Schema::create('historico_acoes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acao');
            $table->string('entidade');
            $table->unsignedBigInteger('entidade_id');
            $table->json('dados_alterados')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['acao', 'created_at']);
            $table->index(['entidade', 'entidade_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historico_acoes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'active']));
    }
};
