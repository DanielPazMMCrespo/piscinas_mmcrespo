<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de idempotência do desconto de stock.
 *
 * `ProcessDailyRecordAfterCreate` tem `tries = 3` e corre `handle()` inteiro
 * em cada tentativa. Quando uma etapa posterior ao stock atirava (o envio de
 * e-mail da não-conformidade, recusado pelo Resend), as 3 tentativas
 * debitavam o mesmo consumo 3 vezes e gravavam 3 logs de movimento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('daily_records', 'stock_processado_em')) {
            Schema::table('daily_records', function (Blueprint $table): void {
                $table->timestamp('stock_processado_em')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('daily_records', 'stock_processado_em')) {
            Schema::table('daily_records', function (Blueprint $table): void {
                $table->dropColumn('stock_processado_em');
            });
        }
    }
};
