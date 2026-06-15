<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Remove colunas do OCR/IA cancelado que ficaram no schema:
//   - record_photos.resultado_ocr (JSON, nunca escrito; OCR cancelado)
//   - filter_checks.resultado_ia / descricao_ia (IA cancelada; o restore migration
//     não as recriou, mas o create original ainda as cria num fresh migrate)
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('record_photos', 'resultado_ocr')) {
            Schema::table('record_photos', function (Blueprint $table) {
                $table->dropColumn('resultado_ocr');
            });
        }

        if (Schema::hasTable('filter_checks')) {
            Schema::table('filter_checks', function (Blueprint $table) {
                if (Schema::hasColumn('filter_checks', 'resultado_ia')) {
                    $table->dropColumn('resultado_ia');
                }
                if (Schema::hasColumn('filter_checks', 'descricao_ia')) {
                    $table->dropColumn('descricao_ia');
                }
            });
        }
    }

    public function down(): void
    {
        // Não restaura — colunas IA foram canceladas intencionalmente.
    }
};
