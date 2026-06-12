<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Ciclo de vida dos incidentes: abrir → resolver. Sem isto o cartão de incidente
// no Kanban nunca fecha. Guards hasColumn para idempotência (PostgreSQL/MySQL).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('incidents', 'status')) {
                // aberto | resolvido
                $table->string('status', 20)->default('aberto')->after('type');
            }
            if (! Schema::hasColumn('incidents', 'resolvido_em')) {
                $table->timestamp('resolvido_em')->nullable();
            }
            if (! Schema::hasColumn('incidents', 'resolvido_por')) {
                $table->foreignId('resolvido_por')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('incidents', 'resolucao')) {
                $table->text('resolucao')->nullable();
            }
        });

        // Consulta quente: "incidentes por resolver".
        Schema::table('incidents', function (Blueprint $table) {
            $table->index(['status', 'ocorreu_em'], 'incidents_status_ocorreu_idx');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropIndex('incidents_status_ocorreu_idx');
            if (Schema::hasColumn('incidents', 'resolvido_por')) {
                $table->dropConstrainedForeignId('resolvido_por');
            }
            $table->dropColumn(['status', 'resolvido_em', 'resolucao']);
        });
    }
};
