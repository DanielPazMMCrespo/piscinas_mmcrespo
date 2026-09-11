<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\AlertLevel;
use App\Constants\AlertType;
use App\Constants\IncidentStatus;
use App\Constants\IncidentType;
use App\Constants\UserRole;
use App\Filament\Pages\EncerramentoPiscinas;
use App\Filament\Pages\EsquemaPiscina;
use App\Filament\Pages\StockHub;
use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\OperationalActionResource;
use App\Models\AlertState;
use App\Models\DailyRecord;
use App\Models\DosingContainer;
use App\Models\Incident;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\SensorOutage;
use App\Models\TapAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

    public function __construct(private readonly SettingsService $settings) {}

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

        $executed = RateLimiter::attempt(
            'move_alert_'.$user->id,
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
            ->with(['instalacao', 'encerramentos'])
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get();

        // Piscinas encerradas hoje não geram alertas operacionais e saem dos
        // denominadores: com elas dentro, "3/5 conformes" ficava errado todos os
        // dias enquanto durasse o encerramento.
        [$encerradas, $abertas] = $piscinas->partition(fn (Pool $piscina) => $piscina->estaEncerradaEm());

        $conformesHoje = 0;

        // Torneiras abertas: uma query única fora do loop.
        $hasTable = Cache::remember('schema_has_tap_alerts', 3600, fn () => Schema::hasTable('tap_alerts'));
        $taps = $hasTable
            ? TapAlert::whereNull('resolved_at')->limit(200)->get()->groupBy('pool_id')
            : collect();

        // Otimização: obter apenas o último registo válido de cada piscina numa só query.
        $ultimosRegistos = DailyRecord::latestPerPool()
            ->whereIn('pool_id', $abertas->pluck('id'))
            ->get()
            ->keyBy('pool_id');

        // Sondas declaradas indisponíveis: informativo (a causa já é conhecida e
        // está a ser tratada), mas tem de estar à vista de quem lê os valores.
        $avariasSonda = SensorOutage::query()
            ->abertas()
            ->whereIn('pool_id', $abertas->pluck('id'))
            ->with('piscina.instalacao')
            ->get();

        foreach ($avariasSonda as $avaria) {
            $alertas[AlertType::SONDA_AVARIA."|{$avaria->id}"] = [
                'nivel' => AlertLevel::NEUTRO,
                'icone' => 'heroicon-o-signal-slash',
                'titulo' => ($avaria->piscina?->nome_completo ?? 'Piscina').': sonda indisponível — '.mb_strtolower($avaria->motivoLabel()),
                'detalhe' => 'Desde '.$avaria->aberta_em->format('d/m H:i')
                    .($avaria->detalhe !== null ? ' · '.Str::limit($avaria->detalhe, 80) : '')
                    .' · os valores do controlador não contam para a conformidade.',
                // Quem não cria ações operacionais (NS, gestor) tem de saber da
                // avaria, mas o link tem de o levar a algo que possa abrir.
                'url' => OperationalActionResource::canCreate()
                    ? OperationalActionResource::getUrl('create', [
                        'pool' => $avaria->pool_id,
                        'tipo' => OperationalAction::TIPO_AVARIA_SONDA,
                    ])
                    : EsquemaPiscina::getUrl(['pool' => $avaria->pool_id]),
                'acao' => OperationalActionResource::canCreate() ? 'Atualizar estado' : 'Ver esquema',
            ];
        }

        foreach ($abertas as $piscina) {
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

        // Uma piscina encerrada com a água em tratamento continua a ter química
        // para cumprir — só deixa de ter registos obrigatórios. Mantém-se o
        // alerta de violação legal, sem entrar nos denominadores. Os últimos
        // registos vêm numa só query (como no caminho das abertas acima), não
        // um por piscina dentro do loop.
        $ultimosRegistosEncerradas = $encerradas->isNotEmpty()
            ? DailyRecord::latestPerPool()
                ->whereIn('pool_id', $encerradas->pluck('id'))
                ->get()
                ->keyBy('pool_id')
            : collect();

        foreach ($encerradas as $piscina) {
            $registo = $ultimosRegistosEncerradas->get($piscina->id);

            if ($piscina->encerramentoEm()?->agua_em_tratamento && $registo?->registado_em->isToday()) {
                $registo->setRelation('piscina', $piscina);
                $violacoes = $this->violacoesLegais($registo);

                if ($violacoes !== []) {
                    // Chave por piscina+data, não por id do registo: uma correção
                    // append-only cria um DailyRecord novo com id diferente e faria
                    // o alerta "ressuscitar" como pendente mesmo já tratado (ver
                    // CLAUDE.md, "estado preso" BUG-04).
                    $alertas[AlertType::FORA_LIMITES."|{$piscina->id}|{$registo->registado_em->toDateString()}"] = [
                        'nivel' => AlertLevel::VERMELHO,
                        'icone' => 'heroicon-o-beaker',
                        'titulo' => "{$piscina->nome_completo}: parâmetros fora dos limites CN 14/DA",
                        'detalhe' => implode(' · ', $violacoes)
                            .' (piscina encerrada, água em tratamento)',
                        'url' => DailyRecordResource::getUrl('index'),
                        'acao' => 'Ver registo',
                    ];
                }
            }
        }

        if ($encerradas->isNotEmpty()) {
            $nomes = $encerradas->map(fn (Pool $p) => $p->nome_completo)->implode(', ');

            $alertas[AlertType::ENCERRADA."|{$hoje}"] = [
                'nivel' => AlertLevel::NEUTRO,
                'icone' => 'heroicon-o-lock-closed',
                'titulo' => $encerradas->count() === 1
                    ? '1 piscina encerrada'
                    : $encerradas->count().' piscinas encerradas',
                'detalhe' => $nomes.' — sem registos diários esperados.',
                'url' => EncerramentoPiscinas::getUrl(),
                'acao' => 'Ver encerramentos',
            ];
        }

        if (! $soPiscinas) {
            $incidentes = Incident::query()
                ->where('status', '!=', IncidentStatus::RESOLVIDO)
                ->where('ocorreu_em', '>=', now()->subDays($this->settings->getInt('incidentes_kanban_dias', 30)))
                ->with(['instalacao', 'utilizador'])
                ->orderByDesc('ocorreu_em')
                ->limit(10)
                ->get();

            foreach ($incidentes as $incidente) {
                $tipoLabel = $incidente->type
                    ? IncidentType::label($incidente->type)
                    : 'sem tipo';

                $alertas[AlertType::INCIDENTE."|{$incidente->id}"] = [
                    'nivel' => AlertLevel::AMARELO,
                    'icone' => 'heroicon-o-bell-alert',
                    'titulo' => ($incidente->instalacao?->name ? "{$incidente->instalacao->name}: " : '')
                        .'incidente — '.$tipoLabel,
                    'detalhe' => $incidente->ocorreu_em->format('d/m H:i')
                        .($incidente->descricao ? ' · '.Str::limit($incidente->descricao, 80) : ''),
                    'url' => IncidentResource::getUrl('view', ['record' => $incidente]),
                    'acao' => 'Ver incidente',
                ];
            }

            // Autonomia Preditiva de Químicos (Prevenção de Esgotamento / Fim de Semana)
            $bidoes = DosingContainer::query()
                ->whereHas('piscina', fn (Builder $q) => $q->where('active', true))
                ->with(['piscina.instalacao', 'logs'])
                ->get();

            foreach ($bidoes as $bidao) {
                $horas = $bidao->horasAutonomia(3);
                $status = $bidao->statusAutonomia(3);
                $estaBaixo = $bidao->estaBaixo();

                if ($status === 'critico' || $status === 'aviso' || $estaBaixo) {
                    $piscinaNome = $bidao->piscina?->nome_completo ?? 'Piscina';
                    $tipo = $bidao->tipoLabel();
                    $nivel = ($status === 'critico' || ((float) $bidao->restante_ml <= 0))
                        ? AlertLevel::VERMELHO
                        : AlertLevel::AMARELO;
                    $esgotaFds = $bidao->esgotaNoFimDeSemana(3);

                    $titulo = $esgotaFds
                        ? "{$piscinaNome}: bidão de {$tipo} esgota no fim de semana!"
                        : "{$piscinaNome}: autonomia de {$tipo} baixa";

                    $previsao = $bidao->previsaoEsgotamento(3);
                    $previsaoTexto = $previsao ? ' · Previsão: '.$previsao->format('d/m H:i') : '';
                    $autonomiaTexto = $horas !== null ? 'Autonomia: ~'.(int) ceil($horas).'h' : 'Nível baixo';

                    $alertas[AlertType::AUTONOMIA_QUIMICA."|{$bidao->id}|{$hoje}"] = [
                        'nivel' => $nivel,
                        'icone' => 'heroicon-o-beaker',
                        'titulo' => $titulo,
                        'detalhe' => "{$autonomiaTexto}{$previsaoTexto} · Nível: {$bidao->percentagem()}% · Abastecer bidão.",
                        'url' => StockHub::getUrl(),
                        'acao' => 'Abastecer bidão',
                    ];
                }
            }

            // Deteção Inteligente de Anomalias de Contador / Possível Fuga de Água
            foreach ($abertas as $piscina) {
                $ultimosContadores = OperationalAction::query()
                    ->where('pool_id', $piscina->id)
                    ->where('tipo', OperationalAction::TIPO_CONTADOR)
                    ->whereNotNull('registado_em')
                    ->orderByDesc('registado_em')
                    ->limit(2)
                    ->get();

                if ($ultimosContadores->count() >= 2) {
                    $maisRecente = $ultimosContadores->first();
                    $anterior = $ultimosContadores->last();

                    $valRecente = (float) ($maisRecente->dados['contador_valor'] ?? 0);
                    $valAnterior = (float) ($anterior->dados['contador_valor'] ?? 0);
                    $deltaV = round($valRecente - $valAnterior, 2);

                    if ($deltaV > 5.0 && $maisRecente->registado_em->greaterThanOrEqualTo(now()->subHours(48))) {
                        $lavagens = OperationalAction::query()
                            ->where('pool_id', $piscina->id)
                            ->where('tipo', OperationalAction::TIPO_LAVAGEM_FILTRO)
                            ->whereBetween('registado_em', [$anterior->registado_em, $maisRecente->registado_em])
                            ->count();

                        $banhistas = (int) DailyRecord::query()
                            ->where('pool_id', $piscina->id)
                            ->whereBetween('registado_em', [$anterior->registado_em->toDateString(), $maisRecente->registado_em->toDateString()])
                            ->sum('ns_banhistas');

                        $consumoEsperado = ($lavagens * 3.0) + ($banhistas * 0.03) + 2.0;

                        if ($deltaV > $consumoEsperado + 8.0) {
                            $inexplicado = round($deltaV - $consumoEsperado, 1);
                            $alertas[AlertType::ANOMALIA_AGUA."|{$piscina->id}|{$maisRecente->id}"] = [
                                'nivel' => AlertLevel::AMARELO,
                                'icone' => 'heroicon-o-exclamation-triangle',
                                'titulo' => "{$piscina->nome_completo}: consumo de água anómalo ({$deltaV} m³)",
                                'detalhe' => "Entrada de {$deltaV} m³ registada (~{$inexplicado} m³ não justificados por lavagens). Verificar boia de compensação ou fugas.",
                                'url' => DailyRecordResource::getUrl('index'),
                                'acao' => 'Ver registos',
                            ];
                        }
                    }
                }
            }
        }

        // Prioridade visual: vermelho > amarelo > neutro (ordem estável).
        uasort($alertas, fn (array $a, array $b) => AlertLevel::weight($a['nivel']) <=> AlertLevel::weight($b['nivel']));

        $resultado = [
            'alertas' => $alertas,
            'totalPiscinas' => $abertas->count(),
            'conformesHoje' => $conformesHoje,
            'encerradas' => $encerradas->count(),
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

        $horaCritica = $this->settings->getInt('sem_registo_hora_critica', 12);
        if (! $temRegistoHoje && now()->hour >= $horaCritica) {
            $alertas[AlertType::SEM_REGISTO."|{$piscina->id}|{$hoje}"] = [
                'nivel' => AlertLevel::VERMELHO,
                'icone' => 'heroicon-o-clipboard-document-list',
                'titulo' => "{$nome}: sem registo diário hoje",
                'detalhe' => $registo
                    ? 'Último registo em '.$registo->registado_em->format('d/m H:i')
                    : 'Nunca teve registos',
                'url' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id, 'quick' => 1]),
                'acao' => 'Criar registo',
            ];
        }

        if ($registo !== null) {
            $registo->setRelation('piscina', $piscina);

            $violacoes = $this->violacoesLegais($registo);
            $violacaoTemp = $this->violacaoTemperatura($registo);

            // Chave por piscina+data, não por id do registo: uma correção append-only
            // cria um DailyRecord novo com id diferente e faria o alerta "ressuscitar"
            // como pendente mesmo já tratado (ver CLAUDE.md, "estado preso" BUG-04).
            $chaveDia = $piscina->id.'|'.$registo->registado_em->toDateString();

            if ($violacoes !== []) {
                $alertas[AlertType::FORA_LIMITES."|{$chaveDia}"] = [
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
                $alertas[AlertType::TEMPERATURA."|{$chaveDia}"] = [
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
    private function gerarAlertasTorneiras(Pool $piscina, string $nome, Collection $taps): array
    {
        $alertas = [];

        foreach ($taps->get($piscina->id, collect()) as $tap) {
            $alertas[AlertType::TORNEIRA."|{$tap->id}"] = [
                'nivel' => AlertLevel::AMARELO,
                'icone' => 'heroicon-o-exclamation-triangle',
                'titulo' => "{$nome}: torneira de água aberta por resolver",
                'detalhe' => 'Aberta desde '.Carbon::parse($tap->opened_at)->format('d/m H:i'),
                'url' => DailyRecordResource::getUrl('create', ['pool' => $piscina->id]),
                'acao' => 'Registar fecho',
            ];
        }

        return $alertas;
    }
}
