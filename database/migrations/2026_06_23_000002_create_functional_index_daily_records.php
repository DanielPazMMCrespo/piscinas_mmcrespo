<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('CREATE INDEX daily_records_date_pool ON daily_records (pool_id, DATE(registado_em))');
        } else {
            DB::statement('CREATE INDEX daily_records_date_pool ON daily_records (pool_id, (DATE(registado_em)))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX daily_records_date_pool');
    }
};
