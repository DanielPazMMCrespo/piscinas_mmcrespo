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
            if (! Schema::hasColumn('daily_records', 'torneira_foto')) {
                $table->string('torneira_foto')->nullable()->after('agua_modo');
            }
        });

        Schema::table('daily_records_archive', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records_archive', 'torneira_foto')) {
                $table->string('torneira_foto')->nullable()->after('agua_modo');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (Schema::hasColumn('daily_records', 'torneira_foto')) {
                $table->dropColumn('torneira_foto');
            }
        });

        Schema::table('daily_records_archive', function (Blueprint $table) {
            if (Schema::hasColumn('daily_records_archive', 'torneira_foto')) {
                $table->dropColumn('torneira_foto');
            }
        });
    }
};
