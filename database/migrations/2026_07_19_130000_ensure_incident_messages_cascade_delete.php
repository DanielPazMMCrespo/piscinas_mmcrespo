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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('incident_messages', function (Blueprint $table) {
            // Tenta remover a chave estrangeira anterior caso exista sem cascade
            try {
                $table->dropForeign('incident_messages_incident_id_foreign');
            } catch (Throwable $e) {
                // Ignorar se não existir
            }

            // Re-adiciona com cascade delete explícito
            $table->foreign('incident_id')
                ->references('id')
                ->on('incidents')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('incident_messages', function (Blueprint $table) {
            try {
                $table->dropForeign(['incident_id']);
            } catch (Throwable $e) {
                // Ignorar
            }

            $table->foreign('incident_id')
                ->references('id')
                ->on('incidents');
        });
    }
};
