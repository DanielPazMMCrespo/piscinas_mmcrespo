<?php declare(strict_types=1);
namespace App\Services;


use App\Constants\AlertLevel;
use App\Constants\AlertType;
use App\Constants\IncidentStatus;
use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\StockInstallationResource;
use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\Pool;
use App\Models\AlertState;
use App\Models\StockInstallation;
use App\Models\TapAlert;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Calcula os alertas operacionais do dia (exception-first) com chaves estáveis,
 * para alimentar o quadro Kanban do dashboard.
 *
 * Cada alerta: key, nivel (vermelho|amarelo|neutro), icone, titulo, detalhe,
 * url, acao. A chave é determinística (tipo|id|data) para o estado Kanban
 * sobreviver a recálculos.
 *
 * Disciplina de cor anti alarm-fatigue: vermelho SÓ para violação legal ou
 * falta de registo já tarde; amarelo para avisos; neutro para informativo.
 */
class AlertasService
{
    /** Memo por-pedido: o hero e o Kanban partilham o mesmo cálculo. */
    private static array $memo = [];

    /**
     * Limpa o memo.
     */
    public static function resetMemo(): void
    {
        self::$memo = [];
    }

    /**
     * Move um alerta entre pendente e resolvido (Kanban).
     *
     * @throws \DomainException Se houver demasiados movimentos (Rate Limiting).
     */
    public function moverAlerta(User $user, string $key, string $status): void
    {
        if (! in_array($status, ['pendente', 'resolvido'], true)) {
            return;
        }

        $executed = \Illuminate\Support\Facades\RateLimiter::attempt(
            'move_alert_' . $user->id,
            30, // 30 movimentos
            function () use ($user, $key, $status) {
                // Recupera do cache (garantido pela chamada do widget antes) ou recalcula se necessário
                $ativos = $this->calcular($user)['alertas'];

                DB::transaction(function () use ($user, $key, $status, $ativos) {
                    AlertState::updateOrCreate(
                        ['alert_key' => $key],
                        [
                            'status' => $status,
                            'payload' => $ativos[$key] ?? null,
                            'moved_by' => $user->id,
                            'moved_at' => now(),
                        ],
                    );
                });
            },
            60 // por minuto
        );

        if (! $executed) {
            throw new \DomainException('Muitos movimentos. Aguarde um momento antes de mover mais cartões.');
        }
    }

    /**
     * @return array{alertas: array<string, array<string, mixed>>, totalPiscinas: int, conformesHoje: int}
     */
    public function calcular(?User $utilizador): array
    {
        $memoKey = (string) ($utilizador?->id ?? 'guest');

        // Verifica memo em-memória primeiro (dentro do mesmo request).
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }

        // Verifica cache (Redis/Database — 5 min TTL).
        $cacheService = app(CacheService::class);
        $cached = $cacheService->getAlerts($utilizador?->id);
        if ($cached !== null) {
            return self::$memo[$memoKey] = $cached;
        }

        $alertas = [];
        $soPiscinas = $utilizador?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;
        $hoje = now()->toDateString();

        $piscinas = Pool::query()
            ->where('active', true)
            ->with('instalacao')
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get();

        $conformesHoje = 0;

        // Torneiras abertas: uma query única fora do loop.
        $hasTable = \Illuminate\Support\Facades\Cache::remember('schema_has_tap_alerts', 3600, fn() => Schema::hasTable('tap_alerts'));
        $taps = $hasTable
            ? TapAlert::whereNull('resolved_at')->limit(200)->get()->groupBy('pool_id')
            : collect();

        // Otimização: obter apenas o último registo válido de cada piscina numa só query.
        $ultimosRegistos = DailyRecord::latestPerPool()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->get()
            ->keyBy('pool_id');

        foreach ($piscinas as $piscina) {
            $nome = $piscina->nome_completo;

            $registo = $ultimosRegistos->get($piscina->id);
            $temRegistoHoje = $registo && $registo->registado_em->isToday();

            // Alertas de registos diários
            $alertasRegistoDiario = $this->gerarAlertasRegistoDiario(
                $piscina, $nome, $registo, $temRegistoHoje, $hoje
            );
            $alertas = array_merge($alertas, $alertasRegistoDiario['alertas']);
            $conformesHoje += $alertasRegistoDiario['conformesHoje'];

            // Alertas de torneiras
            $alertasTorneiras = $this->gerarAlertasTorneiras($piscina, $nome, $taps);
            $alertas = array_merge($alertas, $alertasTorneiras);
        }

        if (! $soPiscinas) {
            $incidentes = Incident::query()
                ->where('status', '!=', IncidentStatus::RESOLVIDO)
                ->where('ocorreu_em', '>=', now()->subDays(30))
                ->with(['instalacao', 'utilizador'])
                ->orderByDesc('ocorreu_em')
                ->limit(10)
                ->get();

            foreach ($incidentes as $incidente) {
                $alertas[AlertType::INCIDENTE."|{$incidente->id}"] = [
                    'nivel' => AlertLevel::NEUTRO,
                    'icone' => 'heroicon-o-bell-alert',
                    'titulo' => ($incidente->instalacao?->name ? "{$incidente->instalacao->name}: " : '')
                        .'incidente — '.($incidente->type ?: 'sem tipo'),
                    'detalhe' => $incidente->ocorreu_em->format('d/m H:i')
                        .($incidente->descricao ? ' · '.Str::limit($incidente->descricao, 80) : ''),
                    'url' => IncidentResource::getUrl('view', ['record' => $incidente]),
                    'acao' => 'Ver incidente',
                ];
            }

            $stockBaixo = StockInstallation::query()
                ->whereColumn('quantity', '<=', 'limite_minimo')
                ->count();

            if ($stockBaixo > 0) {
                $alertas[AlertType::STOCK."|{$hoje}"] = [
                    'nivel' => AlertLevel::AMARELO,
                    'icone' => 'heroicon-o-archive-box-x-mark',
                    'titulo' => $stockBaixo === 1
                        ? '1 produto com stock abaixo do mínimo'
                        : "{$stockBaixo} produtos com stock abaixo do mínimo",
                    'detalhe' => 'Detalhe na tabela "Alertas de Stock Baixo" mais abaixo',
                    'url' => StockInstallationResource::getUrl('index'),
                    'acao' => 'Ver stock',
                ];
            }
        }

        // Prioridade visual: vermelho > amarelo > neutro (ordem estável).
        uasort($alertas, fn (array $a, array $b) => AlertLevel::weight($a['nivel']) <=> AlertLevel::weight($b['nivel']));

        $resultado = [
            'alertas' => $alertas,
            'totalPiscinas' => $piscinas->count(),
            'conformesHoje' => $conformesHoje,
        ];

        // Guarda em cache (5 min TTL — crítico para dashboard).
        $cacheService->cacheAlerts($utilizador?->id, $resultado, 5);

        return self::$memo[$memoKey] = $resultado;
    }

    private function violacoesLegais(DailyRecord $registo): array
    {
        return array_column(
            array_filter($registo->listarViolacoes(), fn (array $v) => $v['parametro'] !== 'temperatura'),
            'mensagem'
        );
    }

    private function violacaoTemperatura(DailyRecord $registo): ?string
    {
        foreach ($registo->listarViolacoes() as $violacao) {
            if ($violacao['parametro'] === 'temperatura') {
                return $violacao['mensagem'];
            }
        }

        return null;
    }

    /**
     * Gera alertas relativos a registos diários (falta de registo, parâmetros fora dos limites, temperatura).
     *
     * @return array{alertas: array<string, array<string, mixed>>, conformesHoje: int}
     */
    private function gerarAlertasRegistoDiario(
        Pool $piscina,
        string $nome,
        ?DailyRecord $registo,
        bool $temRegistoHoje,
        string $hoje
    ): array {
        $alertas = [];
        $conformesHoje = 0;

        if (! $temRegistoHoje) {
            $alertas[AlertType::SEM_REGISTO."|{$piscina->id}|{$hoje}"] = [
                'nivel' => now()->hour >= 12 ? AlertLevel::VERMELHO : AlertLevel::AMARELO,
                'icone' => 'heroicon-o-clipboard-document-list',
                'titulo' => "{$nome}: sem registo diário hoje",
                'detalhe' => $registo
                    ? 'Último registo em '.$registo->registado_em->format('d/m H:i')
                    : 'Nunca teve registos',
                'url' => DailyRecordResource::getUrl('create'),
                'acao' => 'Criar registo',
            ];
        }

        if ($registo !== null) {
            $registo->setRelation('piscina', $piscina);

            $violacoes = $this->violacoesLegais($registo);
            $violacaoTemp = $this->violacaoTemperatura($registo);

            if ($violacoes !== []) {
                $alertas[AlertType::FORA_LIMITES."|{$registo->id}"] = [
                    'nivel' => AlertLevel::VERMELHO,
                    'icone' => 'heroicon-o-beaker',
                    'titulo' => "{$nome}: parâmetros fora dos limites CN 14/DA",
                    'detalhe' => implode(' · ', $violacoes)
                        .' (registo de '.$registo->registado_em->format('d/m H:i').')',
                    'url' => DailyRecordResource::getUrl('index'),
                    'acao' => 'Ver registo',
                ];
            }

            if ($violacaoTemp !== null) {
                $alertas[AlertType::TEMPERATURA."|{$registo->id}"] = [
                    'nivel' => AlertLevel::AMARELO,
                    'icone' => 'heroicon-o-fire',
                    'titulo' => "{$nome}: temperatura fora da gama da piscina",
                    'detalhe' => $violacaoTemp
                        .' (registo de '.$registo->registado_em->format('d/m H:i').')',
                    'url' => DailyRecordResource::getUrl('index'),
                    'acao' => 'Ver registo',
                ];
            }

            if ($temRegistoHoje && $violacoes === [] && $violacaoTemp === null) {
                $conformesHoje = 1;
            }
        }

        return ['alertas' => $alertas, 'conformesHoje' => $conformesHoje];
    }

    /**
     * Gera alertas de torneiras abertas para uma piscina.
     *
     * @return array<string, array<string, mixed>>
     */
    private function gerarAlertasTorneiras(Pool $piscina, string $nome, \Illuminate\Support\Collection $taps): array
    {
        $alertas = [];

        foreach ($taps->get($piscina->id, collect()) as $tap) {
            $alertas[AlertType::TORNEIRA."|{$tap->id}"] = [
                'nivel' => AlertLevel::AMARELO,
                'icone' => 'heroicon-o-exclamation-triangle',
                'titulo' => "{$nome}: torneira de água aberta por resolver",
                'detalhe' => 'Aberta desde '.Carbon::parse($tap->opened_at)->format('d/m H:i'),
                'url' => DailyRecordResource::getUrl('create'),
                'acao' => 'Registar fecho',
            ];
        }

        return $alertas;
    }
}
