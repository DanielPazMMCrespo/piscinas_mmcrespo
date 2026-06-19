<?php declare(strict_types=1);
namespace App\Console\Commands;


use App\Models\DailyRecord;
use Illuminate\Console\Command;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveDailyRecordsCommand extends Command
{
    protected $signature = 'archive:daily-records
                          {--older-than=365 : Days old. Records older than this are archived}
                          {--dry-run : Simulate archival without writing}';

    protected $description = 'Archive daily records older than specified days to daily_records_archive table';

    public function handle(): int
    {
        try {
            $olderThanDays = (int)$this->option('older-than');
            $isDryRun = $this->option('dry-run');

            $this->info("Starting daily records archival (dry-run: " . ($isDryRun ? 'yes' : 'no') . ")");
            $this->info("Archiving records older than {$olderThanDays} days...");

            // Calculate cutoff date
            $cutoffDate = now()->subDays($olderThanDays);
            $this->info("Cutoff date: {$cutoffDate->format('Y-m-d H:i:s')}");

            // Count records to archive
            $recordsToArchive = DB::table('daily_records')
                ->where('created_at', '<', $cutoffDate)
                ->whereDoesntHave('archivalRecord') // Not already archived
                ->count();

            $this->info("Found {$recordsToArchive} records to archive");

            if ($recordsToArchive === 0) {
                $this->info("No records to archive. Exiting.");
                return 0;
            }

            if ($isDryRun) {
                $this->info("[DRY RUN] Would archive {$recordsToArchive} records");
                $this->info("[DRY RUN] Not writing to database (use without --dry-run to commit)");
                return 0;
            }

            // Perform archival in transaction
            $archivedCount = DB::transaction(function () use ($cutoffDate) {
                // Insert into archive table (with explicit column mapping)
                $insertCount = DB::table('daily_records_archive')->insertUsing(
                    [
                        'id', 'pool_id', 'user_id', 'registado_em',
                        'ph', 'cloro_total', 'cloro_livre', 'cloro_combinado',
                        'alcalinidade', 'temperatura_agua', 'turbidez', 'observacoes',
                        'bomba_funcionamento', 'bomba_duracao_minutos', 'bomba_observacoes', 'bomba_foto',
                        'filtro_estado', 'filtro_retrolavagem_duracao', 'filtro_observacoes',
                        'contador_leitura', 'contador_foto',
                        'tanque_nivel', 'tanque_observacoes', 'tanque_foto',
                        'ns_cloro_teste_rapido', 'ns_ph_teste_rapido', 'ns_observacoes',
                        'agua_modo',
                        'e_correcao', 'corrige_registo_id', 'razao_correcao',
                        'created_at', 'updated_at', 'archived_at'
                    ],
                    DB::table('daily_records')
                        ->where('created_at', '<', $cutoffDate)
                        ->select([
                            'id', 'pool_id', 'user_id', 'registado_em',
                            'ph', 'cloro_total', 'cloro_livre', 'cloro_combinado',
                            'alcalinidade', 'temperatura_agua', 'turbidez', 'observacoes',
                            'bomba_funcionamento', 'bomba_duracao_minutos', 'bomba_observacoes', 'bomba_foto',
                            'filtro_estado', 'filtro_retrolavagem_duracao', 'filtro_observacoes',
                            'contador_leitura', 'contador_foto',
                            'tanque_nivel', 'tanque_observacoes', 'tanque_foto',
                            'ns_cloro_teste_rapido', 'ns_ph_teste_rapido', 'ns_observacoes',
                            'agua_modo',
                            'e_correcao', 'corrige_registo_id', 'razao_correcao',
                            'created_at', 'updated_at', DB::raw('NOW() as archived_at')
                        ])
                );

                // Delete from production table (only after successful insert)
                $deleteCount = DB::table('daily_records')
                    ->where('created_at', '<', $cutoffDate)
                    ->delete();

                if ($insertCount !== $deleteCount) {
                    throw new \Exception(
                        "Archival count mismatch: inserted {$insertCount} but deleted {$deleteCount}. Rolling back."
                    );
                }

                return $insertCount;
            });

            $this->info("Successfully archived {$archivedCount} records");

            // Log archival
            Log::info('Daily records archival completed', [
                'archived_count' => $archivedCount,
                'cutoff_date' => $cutoffDate->toDateString(),
                'older_than_days' => (int)$this->option('older-than'),
                'executed_at' => now()->toDateTimeString(),
            ]);

            return 0;
        } catch (\Exception $e) {
            $this->error("Archival failed: {$e->getMessage()}");
            Log::error('Daily records archival failed', [
                'error' => $e->getMessage(),
                'executed_at' => now()->toDateTimeString(),
            ]);
            return 1;
        }
    }
}
