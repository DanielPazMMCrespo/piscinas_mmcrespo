<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correção de relógio por controlador, em minutos.
     *
     * A Hanna Cloud entrega o relógio de parede do controlador etiquetado como
     * UTC, e nem a interface deles é consistente: o histórico da mesma sonda
     * aparece com duas horas de diferença antes e depois de um refresh. O fuso
     * configurado no aparelho não vem em nenhum campo da API, por isso não há
     * como o deduzir — tem de ser declarado aqui.
     *
     * Exemplo real: o controlador da Lazer reporta sempre hora local + 2h,
     * logo leva -120. Os outros quatro reportam hora local e ficam a 0.
     */
    public function up(): void
    {
        Schema::table('hanna_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('hanna_devices', 'ajuste_minutos')) {
                $table->integer('ajuste_minutos')->default(0)->after('raw_info');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hanna_devices', function (Blueprint $table) {
            if (Schema::hasColumn('hanna_devices', 'ajuste_minutos')) {
                $table->dropColumn('ajuste_minutos');
            }
        });
    }
};
