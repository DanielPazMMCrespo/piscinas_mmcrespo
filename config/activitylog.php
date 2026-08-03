<?php

declare(strict_types=1);
use Spatie\Activitylog\Models\Activity;

return [

    'enabled' => env('ACTIVITY_LOGGER_ENABLED', true),

    /*
     * Retenção do trilho de auditoria: 2 anos, alinhado com o arquivamento de
     * registos diários (>1 ano) e com a janela de auditoria DGS. A limpeza é
     * feita pelo `activitylog:clean` agendado em routes/console.php — sem ele
     * a tabela cresce indefinidamente em PostgreSQL.
     */
    'delete_records_older_than_days' => 730,

    'default_log_name' => 'default',

    'default_auth_driver' => null,

    /*
     * Um alvo apagado (soft delete) tem de continuar a aparecer no trilho: a
     * página de Activity Log mostra "(Lixo)" em vez de "(Apagado)".
     */
    'subject_returns_soft_deleted_models' => true,

    'activity_model' => Activity::class,

    'table_name' => env('ACTIVITY_LOGGER_TABLE_NAME', 'activity_log'),

    'database_connection' => env('ACTIVITY_LOGGER_DB_CONNECTION'),
];
