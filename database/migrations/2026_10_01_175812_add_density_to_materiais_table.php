<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materiais', function (Blueprint $table): void {
            $table->decimal('densidade_kg_m3', 9, 2)->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        Schema::table('materiais', function (Blueprint $table): void {
            $table->dropColumn('densidade_kg_m3');
        });
    }
};
