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
        Schema::table('stock_warehouse_logs', function (Blueprint $table) {
            $table->foreignId('stock_warehouse_id')->nullable()->constrained('stock_warehouses')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_warehouse_logs', function (Blueprint $table) {
            $table->dropForeign(['stock_warehouse_id']);
            $table->dropColumn('stock_warehouse_id');
        });
    }
};
