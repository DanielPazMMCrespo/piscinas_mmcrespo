<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Limpeza das colunas do fluxo OCR/wizard antigo (Plano 4 em standby).
// O registo diário atual usa o esquema "caminho da água" (bomba_ferrada,
// filtro_faz_retrolavagem, contador_valor, agua_modo, tanque_ok, ...).
return new class extends Migration
{
    private const ORPHANS = [
        'bomba_estado',
        'pressao_manometro_1',
        'pressao_manometro_2',
        'fez_retrolavagem',
        'contador_leitura',
        'torneira_modo',
        'torneira_com_agua',
        'tanque_nivel',
        'aspeto_agua',
        'lavagem_segundos',
        'enxaguamento_segundos',
    ];

    public function up(): void
    {
        $presentes = array_values(array_filter(
            self::ORPHANS,
            fn (string $col) => Schema::hasColumn('daily_records', $col),
        ));

        if ($presentes === []) {
            return;
        }

        Schema::table('daily_records', function (Blueprint $table) use ($presentes) {
            $table->dropColumn($presentes);
        });
    }

    public function down(): void
    {
        // Colunas legadas sem dados a preservar — não se recriam.
    }
};
