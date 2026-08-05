<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Páginas que um Gestor específico pode ver (Utilizadores gerem gestores
 * individualmente, não só por cargo). Null = tudo visível (default para
 * gestores já existentes e para novos, até o admin restringir).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'paginas_visiveis')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->json('paginas_visiveis')->nullable()->after('ns_permissions');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'paginas_visiveis')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('paginas_visiveis');
            });
        }
    }
};
