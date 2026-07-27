<?php declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('incidents', 'pool_id')) {
                $table->foreignId('pool_id')->nullable()->after('installation_id')->constrained('pools')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            if (Schema::hasColumn('incidents', 'pool_id')) {
                $table->dropConstrainedForeignId('pool_id');
            }
        });
    }
};
