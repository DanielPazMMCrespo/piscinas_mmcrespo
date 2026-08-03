<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A migração original (2026_06_09) criou `activity_log` sem `batch_uuid`; a de
 * restauro (2026_06_12) já a inclui mas só corre se a tabela não existir. Em
 * produção a coluna pode faltar, e sem ela qualquer log agrupado por
 * LogBatch rebenta. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        if (! Schema::hasColumn('activity_log', 'batch_uuid')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->uuid('batch_uuid')->nullable();
            });
        }

        // O filtro por categoria da página de Activity Log ordena sempre por
        // data; sem este índice composto é um seq scan à tabela inteira.
        if (! $this->indexExists('activity_log_log_name_created_idx')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->index(['log_name', 'created_at'], 'activity_log_log_name_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        if ($this->indexExists('activity_log_log_name_created_idx')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropIndex('activity_log_log_name_created_idx');
            });
        }
    }

    private function indexExists(string $nome): bool
    {
        return collect(Schema::getIndexes('activity_log'))
            ->contains(fn (array $indice): bool => $indice['name'] === $nome);
    }
};
