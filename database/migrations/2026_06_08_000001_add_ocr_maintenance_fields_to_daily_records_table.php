<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records', 'bomba_com_bolhas')) {
                $table->boolean('bomba_com_bolhas')->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'pressao_filtro')) {
                $table->decimal('pressao_filtro', 8, 2)->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'estado_valvulas_filtro')) {
                $table->string('estado_valvulas_filtro')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn(['bomba_com_bolhas', 'pressao_filtro', 'estado_valvulas_filtro']);
        });
    }
};
