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
            if (! Schema::hasColumn('daily_records', 'motivo_valor_zero')) {
                $table->text('motivo_valor_zero')->nullable()->after('ns_temperatura');
            }
        });

        Schema::table('daily_records_archive', function (Blueprint $table) {
            if (! Schema::hasColumn('daily_records_archive', 'motivo_valor_zero')) {
                $table->text('motivo_valor_zero')->nullable()->after('ns_temperatura');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (Schema::hasColumn('daily_records', 'motivo_valor_zero')) {
                $table->dropColumn('motivo_valor_zero');
            }
        });

        Schema::table('daily_records_archive', function (Blueprint $table) {
            if (Schema::hasColumn('daily_records_archive', 'motivo_valor_zero')) {
                $table->dropColumn('motivo_valor_zero');
            }
        });
    }
};
