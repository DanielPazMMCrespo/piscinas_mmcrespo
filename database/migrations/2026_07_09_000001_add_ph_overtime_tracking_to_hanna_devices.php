<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rasto de "pH overtime": desde quando o pH está fora da banda proporcional
     * (setpoint ± banda, configurados no próprio controlador Hanna) e se já foi
     * notificado o alarme de overtime (evita re-notificar em cada sync).
     */
    public function up(): void
    {
        Schema::table('hanna_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('hanna_devices', 'ph_out_of_band_since')) {
                $table->timestamp('ph_out_of_band_since')->nullable();
            }
            if (! Schema::hasColumn('hanna_devices', 'ph_overtime_notified_at')) {
                $table->timestamp('ph_overtime_notified_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hanna_devices', function (Blueprint $table) {
            $table->dropColumn(['ph_out_of_band_since', 'ph_overtime_notified_at']);
        });
    }
};
