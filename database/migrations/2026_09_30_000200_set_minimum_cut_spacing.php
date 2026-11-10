<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('espessuras_material')->where('espacamento_minimo_mm', '<', 10)
            ->update(['espacamento_minimo_mm' => 10]);
    }

    public function down(): void
    {
        // The previous per-thickness safety settings cannot be inferred safely.
    }
};
