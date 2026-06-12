<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records', 'bomba_ferrada')) {
                $table->boolean('bomba_ferrada')->nullable()->after('estado_valvulas_filtro');
            }
            if (! Schema::hasColumn('daily_records', 'contador_valor')) {
                $table->decimal('contador_valor', 12, 2)->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'agua_modo')) {
                $table->string('agua_modo', 30)->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'tanque_ok')) {
                $table->boolean('tanque_ok')->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'tanque_observacoes')) {
                $table->text('tanque_observacoes')->nullable();
            }
            if (! Schema::hasColumn('daily_records', 'analises_fotos')) {
                $table->json('analises_fotos')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn([
                'bomba_ferrada',
                'contador_valor',
                'agua_modo',
                'tanque_ok',
                'tanque_observacoes',
                'analises_fotos',
            ]);
        });
    }
};
