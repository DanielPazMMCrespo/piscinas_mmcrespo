<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Índices para as queries quentes do dashboard e dos gráficos:
// "último registo válido por piscina" e "registos dos últimos 14 dias".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->index(['pool_id', 'registado_em'], 'daily_records_pool_registado_idx');
            $table->index('corrige_registo_id', 'daily_records_corrige_idx');
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropIndex('daily_records_pool_registado_idx');
            $table->dropIndex('daily_records_corrige_idx');
        });
    }
};
