<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->boolean('bomba_com_bolhas')->default(false)->after('renovacao_agua');
            $table->decimal('pressao_filtro', 5, 2)->nullable()->after('bomba_com_bolhas');
            $table->string('estado_valvulas_filtro')->nullable()->after('pressao_filtro');
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn(['bomba_com_bolhas', 'pressao_filtro', 'estado_valvulas_filtro']);
        });
    }
};
