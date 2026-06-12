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
            if (!Schema::hasColumn('daily_records', 'bomba_foto')) {
                $table->string('bomba_foto')->nullable()->after('bomba_ferrada');
            }
            if (!Schema::hasColumn('daily_records', 'tanque_foto')) {
                $table->string('tanque_foto')->nullable()->after('tanque_observacoes');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->dropColumn(['bomba_foto', 'tanque_foto']);
        });
    }
};
