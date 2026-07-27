<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('test_pushes', function (Blueprint $table) {
            if (! Schema::hasColumn('test_pushes', 'titulo')) {
                $table->string('titulo')->nullable()->after('tipo');
            }
            if (! Schema::hasColumn('test_pushes', 'corpo')) {
                $table->text('corpo')->nullable()->after('titulo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('test_pushes', function (Blueprint $table) {
            if (Schema::hasColumn('test_pushes', 'titulo')) {
                $table->dropColumn('titulo');
            }
            if (Schema::hasColumn('test_pushes', 'corpo')) {
                $table->dropColumn('corpo');
            }
        });
    }
};
