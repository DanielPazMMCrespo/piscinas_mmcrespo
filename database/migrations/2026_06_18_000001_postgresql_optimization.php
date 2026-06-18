<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Índices de performance para PostgreSQL
        if (Schema::hasTable('daily_records')) {
            Schema::table('daily_records', function (Blueprint $table) {
                // Índices para queries comuns
                if (!Schema::hasIndex('daily_records', 'daily_records_pool_id_registado_em_index')) {
                    $table->index(['pool_id', 'registado_em']);
                }
                if (!Schema::hasIndex('daily_records', 'daily_records_e_correcao_index')) {
                    $table->index('e_correcao');
                }
            });
        }

        if (Schema::hasTable('tap_alerts')) {
            Schema::table('tap_alerts', function (Blueprint $table) {
                if (!Schema::hasIndex('tap_alerts', 'tap_alerts_pool_id_resolved_at_index')) {
                    $table->index(['pool_id', 'resolved_at']);
                }
            });
        }

        if (Schema::hasTable('incidents')) {
            Schema::table('incidents', function (Blueprint $table) {
                if (!Schema::hasIndex('incidents', 'incidents_status_ocorreu_em_index')) {
                    $table->index(['status', 'ocorreu_em']);
                }
            });
        }

        if (Schema::hasTable('stock_warehouse_logs')) {
            Schema::table('stock_warehouse_logs', function (Blueprint $table) {
                if (!Schema::hasIndex('stock_warehouse_logs', 'stock_warehouse_logs_product_id_created_at_index')) {
                    $table->index(['product_id', 'created_at']);
                }
            });
        }

        if (Schema::hasTable('stock_installation_logs')) {
            Schema::table('stock_installation_logs', function (Blueprint $table) {
                if (!Schema::hasIndex('stock_installation_logs', 'stock_installation_logs_installation_id_created_at_index')) {
                    $table->index(['installation_id', 'created_at']);
                }
            });
        }
    }

    public function down(): void
    {
        // Drop indices
        $indices = [
            'daily_records' => ['daily_records_pool_id_registado_em_index', 'daily_records_e_correcao_index'],
            'tap_alerts' => ['tap_alerts_pool_id_resolved_at_index'],
            'incidents' => ['incidents_status_ocorreu_em_index'],
            'stock_warehouse_logs' => ['stock_warehouse_logs_product_id_created_at_index'],
            'stock_installation_logs' => ['stock_installation_logs_installation_id_created_at_index'],
        ];

        foreach ($indices as $table => $tableIndices) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) use ($tableIndices) {
                    foreach ($tableIndices as $index) {
                        if (Schema::hasIndex($table->getTable(), $index)) {
                            $table->dropIndex($index);
                        }
                    }
                });
            }
        }
    }
};
