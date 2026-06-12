<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Estas colunas tambem sao criadas pela migration 2026_06_08_000001.
        // Guardamos com hasColumn para esta migration ser um no-op seguro em BDs
        // onde a 000001 ja correu, sem partir o migrate.
        Schema::table('daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records', 'bomba_com_bolhas')) {
                $table->boolean('bomba_com_bolhas')->default(false)->after('renovacao_agua');
            }
            if (! Schema::hasColumn('daily_records', 'pressao_filtro')) {
                $table->decimal('pressao_filtro', 5, 2)->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'estado_valvulas_filtro')) {
                $table->string('estado_valvulas_filtro')->nullable();
            }
        });
    }

    public function down(): void
    {
        // No-op: as colunas pertencem a migration 2026_06_08_000001.
        // Esta migration nao as remove para nao apagar dados de outra migration.
    }
};
