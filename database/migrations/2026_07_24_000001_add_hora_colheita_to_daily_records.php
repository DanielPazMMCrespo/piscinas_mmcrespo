<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records', 'hora_colheita')) {
                $table->time('hora_colheita')->nullable()->after('registado_em');
            }
        });

        Schema::table('daily_records_archive', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records_archive', 'hora_colheita')) {
                $table->time('hora_colheita')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (Schema::hasColumn('daily_records', 'hora_colheita')) {
                $table->dropColumn('hora_colheita');
            }
        });

        Schema::table('daily_records_archive', function (Blueprint $table) {
            if (Schema::hasColumn('daily_records_archive', 'hora_colheita')) {
                $table->dropColumn('hora_colheita');
            }
        });
    }
};
