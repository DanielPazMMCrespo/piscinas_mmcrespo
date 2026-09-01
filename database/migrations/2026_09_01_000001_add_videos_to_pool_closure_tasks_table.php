<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pool_closure_tasks')) {
            return;
        }

        if (Schema::hasColumn('pool_closure_tasks', 'videos')) {
            return;
        }

        Schema::table('pool_closure_tasks', function (Blueprint $table) {
            $table->json('videos')->nullable()->after('fotos');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pool_closure_tasks', 'videos')) {
            return;
        }

        Schema::table('pool_closure_tasks', function (Blueprint $table) {
            $table->dropColumn('videos');
        });
    }
};
