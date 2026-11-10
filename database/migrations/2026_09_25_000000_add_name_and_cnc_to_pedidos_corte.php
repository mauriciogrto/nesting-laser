<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->string('nome')->nullable();
            $table->string('cnc_saida')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->dropColumn(['nome', 'cnc_saida']);
        });
    }
};
