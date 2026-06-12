<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Converte transparencia de INTEGER para DECIMAL(4,2).
// FNU (Formazin Nephelometric Units) usa decimais (ex.: 0.8, 1.2 FNU).
// O campo estava como INTEGER por herança da coluna "transparência em metros".
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('daily_records', 'transparencia')) {
            return;
        }

        Schema::table('daily_records', function (Blueprint $table) {
            $table->decimal('transparencia', 4, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('daily_records', 'transparencia')) {
            return;
        }

        Schema::table('daily_records', function (Blueprint $table) {
            $table->integer('transparencia')->nullable()->change();
        });
    }
};
