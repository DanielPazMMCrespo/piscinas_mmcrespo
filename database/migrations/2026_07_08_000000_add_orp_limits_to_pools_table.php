<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pools', function (Blueprint $table) {
            $table->integer('orp_min')->nullable()->after('temp_max');
            $table->integer('orp_max')->nullable()->after('orp_min');
        });
    }

    public function down(): void
    {
        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn(['orp_min', 'orp_max']);
        });
    }
};
