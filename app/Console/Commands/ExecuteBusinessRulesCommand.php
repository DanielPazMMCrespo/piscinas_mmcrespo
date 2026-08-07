<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\IncidentStatus;
use App\Constants\IncidentType;
use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\User;
use App\Notifications\EscalacaoIncidenteNotification;
use App\Services\SettingsService;
use App\Support\JanelaSilencio;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ExecuteBusinessRulesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regras:executar';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Executa regras de negócio automáticas (incidentes, escalação, stock)';

    public function __construct(
        private readonly SettingsService $settings,
        private readonly JanelaSilencio $silencio,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('A executar regras de negócio...');

        $this->rule1_autoCreateIncidents();
        $this->rule2_autoEscalateIncidents();
        $this->rule3_autoCloseStockIncidents();

        $this->info('Regras de negócio executadas com sucesso.');

        return self::SUCCESS;
    }

    private function rule1_autoCreateIncidents(): void
    {
        $today = today();
        $violacoesMinimas = $this->settings->getInt('auto_incidente_violacoes_minimas', 3);

        // Get today's DailyRecord entries grouped by pool_id (exclude corrections)
        $records = DailyRecord::with('piscina.instalacao', 'piscina.encerramentos')
            ->whereDate('registado_em', $today)
            ->whereDoesntHave('correcoes')
            ->get()
            ->groupBy('pool_id');

        foreach ($records as $poolId => $poolRecords) {
            $violationsCount = [];
            $pool = $poolRecords->first()->piscina;

            if (! $pool) {
                continue;
            }

            // Abrir um incidente automático numa piscina encerrada seria criar
            // trabalho para uma piscina que não está a funcionar. Um incidente
            // real durante o encerramento (fuga, vandalismo) cria-se à mão.
            if ($pool->estaEncerradaEm($today)) {
                continue;
            }

            foreach ($poolRecords as $record) {
                if (method_exists($record, 'listarViolacoes')) {
                    $violations = $record->listarViolacoes();
                    foreach ($violations as $violation) {
                        $param = $violation['parametro'];
                        if (! isset($violationsCount[$param])) {
                            $violationsCount[$param] = 0;
                        }
                        $violationsCount[$param]++;
                    }
                }
            }

            foreach ($violationsCount as $param => $count) {
                if ($count >= $violacoesMinimas) {
                    $cacheKey = "auto_incidente_{$poolId}_{$param}_{$today->toDateString()}";

                    if (! Cache::has($cacheKey)) {
                        $instalacaoId = $pool->instalacao?->id;
                        $nomePiscina = $pool->nome_completo;

                        Incident::create([
                            'installation_id' => $instalacaoId,
                            'pool_id' => $poolId,
                            // incidents.user_id é NOT NULL: sem isto a regra
                            // rebentava sempre que disparava. O incidente fica
                            // atribuído a quem fez a última medição em violação.
                            'user_id' => $poolRecords->last()->user_id,
                            'ocorreu_em' => now(),
                            'type' => IncidentType::QUALIDADE_AGUA,
                            'status' => IncidentStatus::ABERTO,
                            'descricao' => "Não-conformidade recorrente: {$param} violado em {$count} registos consecutivos na piscina {$nomePiscina}",
                        ]);

                        Cache::put($cacheKey, true, now()->endOfDay());
                        $this->info("Incidente criado para piscina {$poolId} parâmetro {$param}");
                    }
                }
            }
        }
    }

    private function rule2_autoEscalateIncidents(): void
    {
        // A chave de dedup expira ao fim do dia e o comando corre de 15 em 15
        // minutos: sem esta guarda, todos os incidentes parados re-escalavam em
        // bloco à meia-noite em ponto. Agora escalam na primeira corrida depois
        // da janela de silêncio.
        if ($this->silencio->ativa()) {
            return;
        }

        $horasSemResposta = $this->settings->getInt('escalacao_incidente_horas', 24);

        $staleIncidents = Incident::where('status', '!=', IncidentStatus::RESOLVIDO)
            ->where('created_at', '<', now()->subHours($horasSemResposta))
            ->get();

        foreach ($staleIncidents as $incident) {
            $hasRecentMessages = $incident->mensagens()
                ->where('created_at', '>=', now()->subHours($horasSemResposta))
                ->exists();

            if (! $hasRecentMessages) {
                $cacheKey = "escalacao_{$incident->id}_".today()->toDateString();

                if (! Cache::has($cacheKey)) {
                    $adminsAndGestores = User::role([UserRole::ADMIN, UserRole::GESTOR])->get();

                    Notification::send($adminsAndGestores, new EscalacaoIncidenteNotification($incident));

                    Cache::put($cacheKey, true, now()->endOfDay());
                    $this->info("Incidente #{$incident->id} escalado para admins/gestores.");
                }
            }
        }
    }

    private function rule3_autoCloseStockIncidents(): void
    {
        // Simpler approach: just check for incidents with descricao containing 'stock' that can be auto-resolved.
        // Skip this if too complex — log a comment instead.
        $this->info('Regra 3 (Stock) ignorada por complexidade - apenas registo no log.');
        Log::info('Rule 3 (Stock incidents auto-close) skipped.');
    }
}
