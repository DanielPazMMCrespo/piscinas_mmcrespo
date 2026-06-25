<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_installation_logs', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['stock_installation_id']);
                $table->foreign('stock_installation_id')
                    ->references('id')
                    ->on('stock_installations')
                    ->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_installation_logs', function (Blueprint $table) {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['stock_installation_id']);
                $table->foreign('stock_installation_id')
                    ->references('id')
                    ->on('stock_installations');
            }
        });
    }
};
