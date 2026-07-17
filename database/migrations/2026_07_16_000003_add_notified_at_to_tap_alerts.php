<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tap_alerts', function (Blueprint $table) {
            if (!Schema::hasColumn('tap_alerts', 'notified_at')) {
                $table->timestamp('notified_at')->nullable()->after('opened_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tap_alerts', function (Blueprint $table) {
            if (Schema::hasColumn('tap_alerts', 'notified_at')) {
                $table->dropColumn('notified_at');
            }
        });
    }
};
