<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('user_invitations', 'pool_ids')) {
            Schema::table('user_invitations', function (Blueprint $table) {
                $table->json('pool_ids')->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('user_invitations', 'pool_ids')) {
            Schema::table('user_invitations', function (Blueprint $table) {
                $table->dropColumn('pool_ids');
            });
        }
    }
};
