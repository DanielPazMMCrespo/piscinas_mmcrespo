<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ação corretiva tomada quando o registo tem parâmetros fora dos limites legais
// CN 14/DA. Obrigatória no formulário nesse caso (padrão "failed → action").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records', 'acao_corretiva')) {
                $table->text('acao_corretiva')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (Schema::hasColumn('daily_records', 'acao_corretiva')) {
                $table->dropColumn('acao_corretiva');
            }
        });
    }
};
