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
        Schema::table('installations', function (Blueprint $table) {
            $table->boolean('tanques_verificaveis')->default(false)->after('name');
        });

        Schema::table('pools', function (Blueprint $table) {
            $table->integer('ordem_bombas')->default(0)->after('active');
            $table->integer('ordem_filtros')->default(0)->after('ordem_bombas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn(['ordem_bombas', 'ordem_filtros']);
        });

        Schema::table('installations', function (Blueprint $table) {
            $table->dropColumn('tanques_verificaveis');
        });
    }
};
