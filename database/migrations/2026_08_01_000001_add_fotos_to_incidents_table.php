<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos do incidente: sem elas uma avaria descrita a texto obrigava a mandar a
 * evidência fotográfica por WhatsApp, fora do trilho de auditoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('incidents', 'fotos')) {
            Schema::table('incidents', function (Blueprint $table): void {
                $table->json('fotos')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('incidents', 'fotos')) {
            Schema::table('incidents', function (Blueprint $table): void {
                $table->dropColumn('fotos');
            });
        }
    }
};
