<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try {
            Schema::table('alert_states', function (Blueprint $table) {
                $table->unique('alert_key');
            });
        } catch (\Throwable $e) {
            // Already exists or not supported on this DB driver
        }
    }

    public function down(): void
    {
        try {
            Schema::table('alert_states', function (Blueprint $table) {
                $table->dropUnique(['alert_key']);
            });
        } catch (\Throwable $e) {
            // Ignore
        }
    }
};
