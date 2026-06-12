<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Corrige dessincronia: migração original criou stock_warehouse (singular),
// mas Eloquent espera stock_warehouses (plural) por convenção. O erro "no such
// table: stock_warehouses" ocorria ao tentar listar/editar stock de armazém.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_warehouse') && ! Schema::hasTable('stock_warehouses')) {
            Schema::rename('stock_warehouse', 'stock_warehouses');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_warehouses') && ! Schema::hasTable('stock_warehouse')) {
            Schema::rename('stock_warehouses', 'stock_warehouse');
        }
    }
};
