<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->index('created_at', 'pedidos_corte_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('pedidos_corte', function (Blueprint $table): void {
            $table->dropIndex('pedidos_corte_created_at_index');
        });
    }
};
