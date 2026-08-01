<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produto associado ao bidão de dosagem. Sem isto, reabastecer um bidão de 20 L
 * não descontava nada do stock da instalação — e com a dosagem automática da
 * sonda esse é o caminho por onde o químico realmente sai.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dosing_containers', 'product_id')) {
            Schema::table('dosing_containers', function (Blueprint $table): void {
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dosing_containers', 'product_id')) {
            Schema::table('dosing_containers', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('product_id');
            });
        }
    }
};
