<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('espessuras_material', function (Blueprint $table): void {
            $table->decimal('kerf_mm', 6, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('espessuras_material', function (Blueprint $table): void {
            $table->dropColumn('kerf_mm');
        });
    }
};
