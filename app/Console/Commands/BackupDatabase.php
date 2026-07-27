<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Cria um backup da base de dados (SQLite ou PostgreSQL) em storage/backups/.
 * Mantém os últimos 30 backups e remove os anteriores automaticamente.
 *
 * Uso:
 *   php artisan backup:database
 *
 * Agendamento: diário às 03:00 via routes/console.php
 */
class BackupDatabase extends Command
{
    protected $signature = 'backup:database
                            {--keep=30 : Número de backups a manter}';

    protected $description = 'Cria backup da BD (SQLite copia ficheiro; PostgreSQL usa pg_dump)';

    public function handle(): int
    {
        $connection = config('database.default');
        $timestamp = now()->format('Y-m-d_His');
        $backupDir = storage_path('backups');

        if (! is_dir($backupDir) && ! mkdir($backupDir, 0755, true) && ! is_dir($backupDir)) {
            $this->error("Não foi possível criar o diretório de backups: {$backupDir}");

            return self::FAILURE;
        }

        $resultado = match ($connection) {
            'sqlite' => $this->backupSqlite($backupDir, $timestamp),
            'pgsql' => $this->backupPostgres($backupDir, $timestamp),
            default => $this->error("Driver '{$connection}' não suportado — faz o backup manualmente.") || false,
        };

        if (! $resultado) {
            return self::FAILURE;
        }

        $this->pruneOldBackups($backupDir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    private function backupSqlite(string $dir, string $ts): bool
    {
        $origem = config('database.connections.sqlite.database');
        if (! file_exists($origem)) {
            $this->error("Ficheiro SQLite não encontrado: {$origem}");

            return false;
        }

        $destino = "{$dir}/backup_{$ts}.sqlite";
        if (! copy($origem, $destino)) {
            $this->error("Falha ao copiar para {$destino}");

            return false;
        }

        $this->info("✓ Backup SQLite criado: {$destino} (".round(filesize($destino) / 1024, 1).' KB)');

        return true;
    }

    private function backupPostgres(string $dir, string $ts): bool
    {
        $host = (string) config('database.connections.pgsql.host', '127.0.0.1');
        $port = (string) config('database.connections.pgsql.port', '5432');
        $db = (string) config('database.connections.pgsql.database');
        $user = (string) config('database.connections.pgsql.username');
        $password = (string) config('database.connections.pgsql.password');
        $destino = "{$dir}/backup_{$ts}.dump";

        // PGPASSWORD via putenv evita que a password apareça nos args do processo
        // (não visível em `ps aux`, apenas no ambiente do processo filho).
        putenv("PGPASSWORD={$password}");

        $cmd = sprintf(
            'pg_dump -h %s -p %s -U %s --format=custom --file=%s %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($user),
            escapeshellarg($destino),
            escapeshellarg($db),
        );

        exec($cmd, $output, $exitCode);
        putenv('PGPASSWORD=');

        if ($exitCode !== 0) {
            $this->error('Falha no pg_dump (exit '.$exitCode.'). Verifica que pg_dump está no PATH e que as credenciais estão corretas.');

            return false;
        }

        $this->info("✓ Backup PostgreSQL criado: {$destino} (".round(filesize($destino) / 1024, 1).' KB)');

        return true;
    }

    private function pruneOldBackups(string $dir, int $keep): void
    {
        $backups = glob("{$dir}/backup_*");
        if ($backups === false || count($backups) <= $keep) {
            return;
        }

        sort($backups);
        $toDelete = array_slice($backups, 0, count($backups) - $keep);

        foreach ($toDelete as $ficheiro) {
            unlink($ficheiro);
        }

        $this->line('  Backups antigos removidos: '.count($toDelete));
    }
}
