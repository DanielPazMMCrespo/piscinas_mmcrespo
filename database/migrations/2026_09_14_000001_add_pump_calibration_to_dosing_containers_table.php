<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dosing_containers', function (Blueprint $table) {
            $table->string('bomba_modelo', 50)->default('standard');
            $table->decimal('bomba_capacidade_max_lh', 5, 2)->default(3.50);
            $table->unsignedTinyInteger('bomba_potenciometro_percent')->default(100);
            $table->decimal('fator_correcao', 6, 3)->default(1.000);
        });

        // Configurar o bidão de cloro da piscina de Competição com a bomba Hanna BL10-2 a 60% (25L)
        $competicao = DB::table('pools')->where('name', 'Competição')->first();
        if ($competicao) {
            DB::table('dosing_containers')
                ->where('pool_id', $competicao->id)
                ->where('tipo', 'cloro')
                ->update([
                    'capacidade_ml' => 25000,
                    'restante_ml' => 0.00,
                    'bomba_modelo' => 'hanna_bl10_2',
                    'bomba_capacidade_max_lh' => 10.80,
                    'bomba_potenciometro_percent' => 60,
                    'fator_correcao' => 1.851,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('dosing_containers', function (Blueprint $table) {
            $table->dropColumn([
                'bomba_modelo',
                'bomba_capacidade_max_lh',
                'bomba_potenciometro_percent',
                'fator_correcao',
            ]);
        });
    }
};
