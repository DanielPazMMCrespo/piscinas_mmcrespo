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
        Schema::table('daily_records_archive', function (Blueprint $table) {
            $table->string('agua_modo', 30)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_records_archive', function (Blueprint $table) {
            $table->enum('agua_modo', ['on_com_agua', 'on_sem_agua', 'off', 'desconhecido'])->default('desconhecido')->change();
        });
    }
};
