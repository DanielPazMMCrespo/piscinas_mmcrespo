<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Prevenir falhas limpando duplicados pré-existentes
        DB::statement('
            DELETE FROM sensor_readings 
            WHERE id NOT IN (
                SELECT MIN(id) 
                FROM sensor_readings 
                GROUP BY hanna_device_id, lida_em
            )
        ');

        Schema::table('sensor_readings', function (Blueprint $table) {
            $table->unique(['hanna_device_id', 'lida_em'], 'sensor_readings_device_lida_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sensor_readings', function (Blueprint $table) {
            $table->dropUnique('sensor_readings_device_lida_unique');
        });
    }
};
