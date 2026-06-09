<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Novos campos NS e filtro em daily_records
        Schema::table('daily_records', function (Blueprint $table) {
            $table->string('ns_foto')->nullable()->after('observacoes');
            $table->decimal('ns_ph', 8, 2)->nullable()->after('ns_foto');
            $table->decimal('ns_cloro_livre', 8, 2)->nullable()->after('ns_ph');
            $table->decimal('ns_cloro_total', 8, 2)->nullable()->after('ns_cloro_livre');
            $table->decimal('ns_temperatura', 8, 2)->nullable()->after('ns_cloro_total');
            $table->boolean('filtro_faz_retrolavagem')->default(false)->after('ns_temperatura');
            $table->string('filtro_foto_retrolavagem')->nullable()->after('filtro_faz_retrolavagem');
            $table->string('filtro_foto_enxaguamento')->nullable()->after('filtro_foto_retrolavagem');
            $table->string('filtro_foto_posicao_normal')->nullable()->after('filtro_foto_enxaguamento');
        });

        // Volume da piscina (m³) — necessário para a calculadora de dosagem
        Schema::table('pools', function (Blueprint $table) {
            $table->decimal('volume', 8, 2)->nullable()->after('temp_max');
        });
    }

    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn([
                'ns_foto', 'ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura',
                'filtro_faz_retrolavagem', 'filtro_foto_retrolavagem',
                'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal',
            ]);
        });

        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn('volume');
        });
    }
};
