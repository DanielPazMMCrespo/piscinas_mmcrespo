<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Remove tabelas vazias (nunca preenchidas) para limpeza de BD. Auditoria 2026-06-12:
// 16 tabelas com 0 registos identificadas como órfãs.
// - Tabelas queue/cache Laravel: job_batches, jobs, cache_locks (pode deixar, inofensivas)
// - Tabelas permissions Spatie não usadas: model_has_permissions, role_has_permissions,
//   permissions (a app usa só 4 roles/admin/tecnico/nadador_salvador sem granularidade)
// - Tabelas de relacionamento não usadas: incident_pools, incident_products
//   (incidentes não se relacionam com piscinas/produtos na v1)
// - Log tables vazias: activity_log (spatie/activitylog não ativado), stock_*_logs
//   (nunca preenchidas via CreateDailyRecord)
// - Misc: password_reset_tokens (sem reset flow), notifications (usa FilamentNotifications, não DB)
//   failed_jobs (queue nunca lançou errros), filter_checks (OCR cancelado)
return new class extends Migration
{
    public function up(): void
    {
        $orphaned = [
            // Tabelas Laravel framework opcionais e não usadas pela app:
            'activity_log',
            'cache_locks',
            'failed_jobs',
            'incident_pools',
            'incident_products',
            'job_batches',
            'jobs',
            // NÃO incluir: permissions/model_has_permissions/role_has_permissions (Spatie precisa delas)
            // NÃO incluir: notifications (Filament), filter_checks, stock_*_logs (usadas ativamente)
            'password_reset_tokens',
        ];

        foreach ($orphaned as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Não restaura — limpeza é intencional e unidirecional.
    }
};
