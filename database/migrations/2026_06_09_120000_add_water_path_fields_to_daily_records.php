<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->boolean('bomba_ferrada')->nullable()->after('estado_valvulas_filtro');
            $table->decimal('contador_valor', 12, 2)->nullable()->after('bomba_ferrada');
            $table->string('agua_modo', 30)->nullable()->after('contador_valor');
            $table->boolean('tanque_ok')->nullable()->after('agua_modo');
            $table->text('tanque_observacoes')->nullable()->after('tanque_ok');
            $table->json('analises_fotos')->nullable()->after('tanque_observacoes');
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
