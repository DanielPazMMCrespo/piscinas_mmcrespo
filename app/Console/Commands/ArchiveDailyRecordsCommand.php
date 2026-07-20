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
                // Get the list of daily record IDs that will be archived
                $ids = DB::table('daily_records')
                    ->where('created_at', '<', $cutoffDate)
                    ->pluck('id')
                    ->toArray();

                if (empty($ids)) {
                    return 0;
                }

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
                        'agua_modo', 'torneira_foto',
                        'e_correcao', 'corrige_registo_id', 'razao_correcao',
                        'created_at', 'updated_at', 'archived_at'
                    ],
                    DB::table('daily_records')
                        ->whereIn('id', $ids)
                        ->select([
                            'id', 'pool_id', 'user_id', 'registado_em',
                            'ph', 'cloro_total', 'cloro_livre',
                            DB::raw('NULL as cloro_combinado'),
                            DB::raw('NULL as alcalinidade'),
                            'temperatura as temperatura_agua',
                            'transparencia as turbidez',
                            'observacoes',
                            DB::raw("'desconhecido' as bomba_funcionamento"),
                            DB::raw('NULL as bomba_duracao_minutos'),
                            DB::raw('NULL as bomba_observacoes'),
                            'bomba_foto',
                            DB::raw("'desconhecido' as filtro_estado"),
                            DB::raw('NULL as filtro_retrolavagem_duracao'),
                            DB::raw('NULL as filtro_observacoes'),
                            'contador_valor as contador_leitura',
                            'contador_foto',
                            DB::raw("'desconhecido' as tanque_nivel"),
                            'tanque_observacoes',
                            'tanque_foto',
                            DB::raw('NULL as ns_cloro_teste_rapido'),
                            DB::raw('NULL as ns_ph_teste_rapido'),
                            DB::raw('NULL as ns_observacoes'),
                            'agua_modo',
                            'torneira_foto',
                            'e_correcao',
                            'corrige_registo_id',
                            'razao_correcao',
                            'created_at',
                            'updated_at',
                            DB::raw("'" . now()->toDateTimeString() . "' as archived_at")
                        ])
                );

                if ($insertCount !== count($ids)) {
                    throw new \Exception(
                        "Archival count mismatch: found " . count($ids) . " daily records but inserted {$insertCount} into archive."
                    );
                }

                // Copy additions to archive
                DB::table('record_additions_archive')->insertUsing(
                    ['id', 'daily_record_id', 'product_id', 'quantity', 'created_at', 'updated_at'],
                    DB::table('record_additions')
                        ->whereIn('daily_record_id', $ids)
                        ->select(['id', 'daily_record_id', 'product_id', 'quantity', 'created_at', 'updated_at'])
                );

                // Copy photos to archive
                DB::table('record_photos_archive')->insertUsing(
                    ['id', 'daily_record_id', 'type', 'path', 'resultado_ocr', 'created_at', 'updated_at'],
                    DB::table('record_photos')
                        ->whereIn('daily_record_id', $ids)
                        ->select(['id', 'daily_record_id', 'type', 'path', 'resultado_ocr', 'created_at', 'updated_at'])
                );

                // Delete from production table (cascades deletion to additions and photos)
                $deleteCount = DB::table('daily_records')
                    ->whereIn('id', $ids)
                    ->delete();

                if ($deleteCount !== count($ids)) {
                    throw new \Exception(
                        "Archival delete count mismatch: inserted " . count($ids) . " records but deleted {$deleteCount}. Rolling back."
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
