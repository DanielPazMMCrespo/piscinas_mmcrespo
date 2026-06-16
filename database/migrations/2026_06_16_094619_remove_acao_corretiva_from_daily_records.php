<?php

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
            if (Schema::hasColumn('daily_records', 'acao_corretiva')) {
                $table->dropColumn('acao_corretiva');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            if (!Schema::hasColumn('daily_records', 'acao_corretiva')) {
                $table->text('acao_corretiva')->nullable()->after('transparencia');
            }
        });
    }
};
