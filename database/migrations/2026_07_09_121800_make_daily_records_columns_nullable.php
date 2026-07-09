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
            $table->decimal('cloro_livre', 4, 2)->nullable()->change();
            $table->decimal('cloro_total', 4, 2)->nullable()->change();
            $table->decimal('ph', 4, 2)->nullable()->change();
            $table->decimal('temperatura', 4, 1)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_records', function (Blueprint $table) {
            $table->decimal('cloro_livre', 4, 2)->nullable(false)->change();
            $table->decimal('cloro_total', 4, 2)->nullable(false)->change();
            $table->decimal('ph', 4, 2)->nullable(false)->change();
            $table->decimal('temperatura', 4, 1)->nullable(false)->change();
        });
    }
};
