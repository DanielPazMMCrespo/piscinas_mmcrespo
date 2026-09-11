<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\DosingContainer;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListDailyRecords extends ListRecords
{
    protected static string $resource = DailyRecordResource::class;

    protected static string $view = 'filament.resources.daily-records.pages.list-daily-records';

    public ?int $filterPoolId = null;

    public ?string $filterTipo = null;

    protected $listeners = [
        'operationalActionSaved' => '$refresh',
        'dailyRecordSaved' => '$refresh',
    ];

    public function filterByPool(?int $poolId): void
    {
        $this->filterPoolId = $poolId;
    }

    public function filterByType(?string $tipo): void
    {
        $this->filterTipo = $tipo;
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Actions\CreateAction::make()
                ->label('Nova Medição')
                ->icon('heroicon-o-plus')
                ->color('primary'),
            Actions\Action::make('novaAcaoTecnica')
                ->label('Ação Técnica')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]))
                ->slideOver()
                ->modalHeading('Registar Ação Técnica na Piscina')
                ->modalDescription('Manutenção de máquinas (filtros, contadores, químicos, etc.)')
                ->modalSubmitActionLabel('Gravar Ação')
                ->form([
                    Forms\Components\Select::make('pool_id')
                        ->label('Piscina')
                        ->options(fn () => Pool::query()
                            ->where('active', true)
                            ->with('instalacao')
                            ->orderBy('installation_id')
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn (Pool $p) => [$p->id => $p->nome_completo])
                            ->toArray()
                        )
                        ->default(fn () => request()->integer('pool')
                            ?: $this->filterPoolId
                            ?: OperationalAction::query()->where('user_id', auth()->id())->orderByDesc('registado_em')->value('pool_id')
                            ?: DailyRecord::query()->where('user_id', auth()->id())->orderByDesc('registado_em')->value('pool_id')
                            ?: Pool::query()->where('active', true)->value('id')
                        )
                        ->searchable()
                        ->required()
                        ->live(),

                    Forms\Components\Select::make('tipo')
                        ->label('Tipo de Ação')
                        ->options([
                            OperationalAction::TIPO_LAVAGEM_FILTRO => 'Lavagem de filtro',
                            OperationalAction::TIPO_ENXAGUAMENTO_FILTRO => 'Enxaguamento de filtro',
                            OperationalAction::TIPO_CONTADOR => 'Contador de água (m³)',
                            OperationalAction::TIPO_REABASTECIMENTO_BIDAO => 'Reabastecimento de bidão',
                            OperationalAction::TIPO_TORNEIRA => 'Torneira / entrada de água',
                            OperationalAction::TIPO_BOMBA => 'Bomba / motor',
                            OperationalAction::TIPO_TANQUE => 'Tanque de compensação',
                            OperationalAction::TIPO_TRATAMENTO_CHOQUE => 'Tratamento de choque / hipercloração',
                            OperationalAction::TIPO_ASPIRACAO_FUNDO => 'Aspiração de fundo / robô',
                            OperationalAction::TIPO_LIMPEZA_PRAIAS => 'Limpeza de praias / grelhas',
                            OperationalAction::TIPO_MANUTENCAO_EQUIPAMENTO => 'Manutenção / calibração',
                            OperationalAction::TIPO_AVARIA_SONDA => 'Sonda: avaria / indisponibilidade',
                            OperationalAction::TIPO_OUTRO => 'Outro',
                        ])
                        ->default(OperationalAction::TIPO_LAVAGEM_FILTRO)
                        ->required()
                        ->live(),

                    Forms\Components\DateTimePicker::make('registado_em')
                        ->label('Data e Hora')
                        ->default(now())
                        ->seconds(false)
                        ->required(),

                    // Secção Lavagem / Enxaguamento de Filtro
                    Forms\Components\Fieldset::make('Detalhes da Lavagem de Filtro')
                        ->visible(fn (Forms\Get $get) => in_array($get('tipo'), [
                            OperationalAction::TIPO_LAVAGEM_FILTRO,
                            OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                        ], true))
                        ->schema([
                            Forms\Components\TextInput::make('filtro_nome')
                                ->label('Identificação do filtro (opcional)')
                                ->placeholder('Ex.: Filtro 1, Filtro Principal'),

                            Forms\Components\TextInput::make('duracao_min')
                                ->label('Duração')
                                ->numeric()
                                ->minValue(1)
                                ->default(3)
                                ->suffix('minutos')
                                ->helperText('Recomendado: 3 min (normal) ou 5 min (intensivo)')
                                ->extraInputAttributes(['inputmode' => 'numeric']),

                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('pressao_antes_bar')
                                    ->label('Pressão Inicial')
                                    ->numeric()->step(0.01)->suffix('bar')
                                    ->extraInputAttributes(['inputmode' => 'decimal']),

                                Forms\Components\TextInput::make('pressao_depois_bar')
                                    ->label('Pressão Final')
                                    ->numeric()->step(0.01)->suffix('bar')
                                    ->extraInputAttributes(['inputmode' => 'decimal']),
                            ]),
                        ]),

                    // Secção Contador
                    Forms\Components\Fieldset::make('Leitura de Contador')
                        ->visible(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_CONTADOR)
                        ->schema([
                            Forms\Components\TextInput::make('contador_valor')
                                ->label('Leitura do Contador')
                                ->numeric()
                                ->step(0.01)
                                ->suffix('m³')
                                ->required(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_CONTADOR)
                                ->extraInputAttributes(['inputmode' => 'decimal']),
                        ]),

                    // Secção Reabastecimento Bidão
                    Forms\Components\Fieldset::make('Reabastecimento de Químico')
                        ->visible(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                        ->schema([
                            Forms\Components\Select::make('bidao_tipo')
                                ->label('Produto')
                                ->options([
                                    DosingContainer::TIPO_CLORO => 'Cloro líquido',
                                    DosingContainer::TIPO_PH_MENOS => 'Redutor de pH (pH-)',
                                    'ambos' => 'Ambos (Cloro e pH-)',
                                ])
                                ->required(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                                ->default(DosingContainer::TIPO_CLORO),

                            Forms\Components\TextInput::make('quantidade_l')
                                ->label('Quantidade adicionada')
                                ->numeric()
                                ->minValue(0.5)
                                ->step(0.5)
                                ->suffix('Litros')
                                ->helperText('Deixe vazio para encher o bidão até à capacidade total')
                                ->extraInputAttributes(['inputmode' => 'decimal']),

                            Forms\Components\Checkbox::make('reabastecer_todas_leiria')
                                ->label('Aplicar às 3 piscinas de Leiria')
                                ->helperText('Cria o mesmo reabastecimento nas piscinas Competição, Lazer e Infantil'),
                        ]),

                    // Secção Torneira
                    Forms\Components\Fieldset::make('Entrada de Água / Torneira')
                        ->visible(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_TORNEIRA)
                        ->schema([
                            Forms\Components\Select::make('agua_modo')
                                ->label('Modo da Torneira')
                                ->options([
                                    'auto_com_agua' => 'Auto com água',
                                    'auto_sem_agua' => 'Auto sem água',
                                    'on_com_agua' => 'ON com água (encher piscina)',
                                    'on_sem_agua' => 'ON sem água',
                                    'off' => 'OFF (fechada)',
                                ])
                                ->required(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_TORNEIRA)
                                ->default('auto_com_agua'),
                        ]),

                    // Secção Bomba
                    Forms\Components\Fieldset::make('Bomba de Circulação')
                        ->visible(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_BOMBA)
                        ->schema([
                            Forms\Components\TextInput::make('bomba_nome')
                                ->label('Nome da bomba')
                                ->placeholder('Ex.: Bomba 1'),
                            Forms\Components\Select::make('bomba_acao')
                                ->label('Intervenção')
                                ->options([
                                    'ferragem' => 'Ferragem de bomba',
                                    'limpeza_pre_filtro' => 'Limpeza de pré-filtro',
                                    'paragem_arranque' => 'Paragem / Arranque',
                                    'manutencao' => 'Manutenção / Reparação',
                                ])
                                ->default('limpeza_pre_filtro'),
                            Forms\Components\Toggle::make('bomba_ferrada')
                                ->label('Bomba ferrada e operacional')
                                ->default(true),
                        ]),

                    // Secção Tanque
                    Forms\Components\Fieldset::make('Tanque de Compensação')
                        ->visible(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE)
                        ->schema([
                            Forms\Components\TextInput::make('tanque_nome')
                                ->label('Identificação')
                                ->placeholder('Ex.: Tanque de Compensação'),
                            Forms\Components\TextInput::make('tanque_nivel_pct')
                                ->label('Nível estimado')
                                ->numeric()->minValue(0)->maxValue(100)
                                ->suffix('%')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            Forms\Components\Toggle::make('tanque_ok')
                                ->label('Tanque OK')
                                ->default(true),
                        ]),

                    // Secção Sonda
                    Forms\Components\Fieldset::make('Sonda Hanna')
                        ->visible(fn (Forms\Get $get) => $get('tipo') === OperationalAction::TIPO_AVARIA_SONDA)
                        ->schema([
                            Forms\Components\Select::make('sonda_estado')
                                ->label('Estado')
                                ->options([
                                    'avaria' => 'Declarar avaria / manutenção',
                                    'resolvido' => 'Resolvido / Reposto em serviço',
                                ])
                                ->default('avaria'),
                            Forms\Components\TextInput::make('sonda_motivo')
                                ->label('Motivo / Diagnóstico')
                                ->placeholder('Ex.: Elétrodo sujo, sem fluxo de água, calibrar'),
                        ]),

                    // Observações & Foto
                    Forms\Components\Textarea::make('observacoes')
                        ->label('Observações')
                        ->rows(2),

                    Forms\Components\FileUpload::make('foto')
                        ->label('Foto comprovativa (opcional)')
                        ->image()
                        ->directory('operational-actions')
                        ->maxSize(10240)
                        ->extraInputAttributes(['capture' => 'environment']),
                ])
                ->action(function (array $data) {
                    $userId = auth()->id();
                    $tipo = $data['tipo'];
                    $poolId = (int) $data['pool_id'];
                    $registadoEm = $data['registado_em'] ?? now();
                    $observacoes = $data['observacoes'] ?? null;
                    $foto = $data['foto'] ?? null;

                    $dados = [];
                    foreach ([
                        'filtro_nome', 'duracao_min', 'pressao_antes_bar', 'pressao_depois_bar',
                        'contador_valor', 'bidao_tipo', 'quantidade_l', 'agua_modo',
                        'bomba_nome', 'bomba_acao', 'bomba_ferrada',
                        'tanque_nome', 'tanque_nivel_pct', 'tanque_ok', 'sonda_estado', 'sonda_motivo',
                    ] as $k) {
                        if (isset($data[$k]) && $data[$k] !== null && $data[$k] !== '') {
                            $dados[$k] = $data[$k];
                        }
                    }

                    if ($tipo === OperationalAction::TIPO_REABASTECIMENTO_BIDAO && ! empty($data['reabastecer_todas_leiria'])) {
                        $poolIds = Installation::where('name', 'Leiria')->first()
                            ?->piscinas()->where('active', true)->pluck('id') ?? collect([$poolId]);
                        foreach ($poolIds as $pId) {
                            OperationalAction::create([
                                'pool_id' => $pId,
                                'user_id' => $userId,
                                'tipo' => $tipo,
                                'registado_em' => $registadoEm,
                                'dados' => $dados,
                                'observacoes' => $observacoes,
                                'foto' => $foto,
                            ]);
                        }
                    } else {
                        OperationalAction::create([
                            'pool_id' => $poolId,
                            'user_id' => $userId,
                            'tipo' => $tipo,
                            'registado_em' => $registadoEm,
                            'dados' => $dados,
                            'observacoes' => $observacoes,
                            'foto' => $foto,
                        ]);
                    }

                    Notification::make()
                        ->title('Ação técnica registada com sucesso')
                        ->success()
                        ->send();
                }),
        ];

        return $actions;
    }

    public function getTabs(): array
    {
        $phMin = DailyRecord::getPhMin();
        $phMax = DailyRecord::getPhMax();

        return [
            'todos' => Tab::make('Todos'),
            'hoje' => Tab::make('Hoje')
                ->badge(fn () => DailyRecordResource::getEloquentQuery()->whereDate('registado_em', today())->count())
                ->badgeColor('primary')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('registado_em', today())),
            'nao_conformes' => Tab::make('Não conformes')
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(function (Builder $q) use ($phMin, $phMax) {
                    $q->whereRaw('COALESCE(ph, ns_ph) < ? OR COALESCE(ph, ns_ph) > ?', [$phMin, $phMax])
                        ->orWhereRaw('COALESCE(cloro_livre, ns_cloro_livre) < ? OR COALESCE(cloro_livre, ns_cloro_livre) > ?', [0.4, 3.0])
                        ->orWhereRaw('(COALESCE(cloro_total, ns_cloro_total) - COALESCE(cloro_livre, ns_cloro_livre)) > ?', [0.6]);
                })),
            'ultimos_7_dias' => Tab::make('Últimos 7 dias')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('registado_em', '>=', now()->subDays(7))),
        ];
    }

    public function getPiscinas(): Collection
    {
        $user = auth()->user();
        $query = Pool::query()->where('active', true)->with('instalacao');

        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('pools.id', $user->piscinas()->pluck('pools.id'));
        }

        return $query->orderBy('installation_id')->orderBy('name')->get();
    }

    public function getKpis(): array
    {
        $user = auth()->user();
        $poolQuery = Pool::query()->where('active', true);
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolQuery->whereIn('pools.id', $user->piscinas()->pluck('pools.id'));
        }
        $poolIds = $poolQuery->pluck('id');

        $hojeRegistos = DailyRecord::query()
            ->whereIn('pool_id', $poolIds)
            ->whereDate('registado_em', today())
            ->count();

        $piscinasComMedicaoHoje = DailyRecord::query()
            ->whereIn('pool_id', $poolIds)
            ->whereDate('registado_em', today())
            ->distinct('pool_id')
            ->count('pool_id');

        $totalPiscinas = $poolIds->count();

        $ultimaLavagem = OperationalAction::query()
            ->whereIn('pool_id', $poolIds)
            ->whereIn('tipo', [OperationalAction::TIPO_LAVAGEM_FILTRO, OperationalAction::TIPO_ENXAGUAMENTO_FILTRO])
            ->latest('registado_em')
            ->with('piscina')
            ->first();

        $numRenovacoes = DailyRecord::query()
            ->whereIn('pool_id', $poolIds)
            ->where('registado_em', '>=', now()->startOfMonth())
            ->where('renovacao_agua', true)
            ->count();

        $contadoresMes = OperationalAction::query()
            ->whereIn('pool_id', $poolIds)
            ->where('tipo', OperationalAction::TIPO_CONTADOR)
            ->where('registado_em', '>=', now()->startOfMonth())
            ->count();

        return [
            'medicoes_hoje' => $hojeRegistos,
            'piscinas_medidas' => $piscinasComMedicaoHoje,
            'total_piscinas' => $totalPiscinas,
            'ultima_lavagem' => $ultimaLavagem,
            'renovacoes_mes' => $numRenovacoes,
            'contadores_mes' => $contadoresMes,
        ];
    }

    public function getTimelineEvents(): Collection
    {
        $user = auth()->user();
        $poolQuery = Pool::query()->where('active', true);
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolQuery->whereIn('pools.id', $user->piscinas()->pluck('pools.id'));
        }
        if ($this->filterPoolId) {
            $poolQuery->where('pools.id', $this->filterPoolId);
        }
        $poolIds = $poolQuery->pluck('id');

        $leiturasQuery = DailyRecord::query()
            ->whereIn('pool_id', $poolIds)
            ->with(['piscina.instalacao', 'utilizador'])
            ->latest('registado_em')
            ->take(30);

        $acoesQuery = OperationalAction::query()
            ->whereIn('pool_id', $poolIds)
            ->with(['piscina.instalacao', 'utilizador'])
            ->latest('registado_em')
            ->take(30);

        if ($this->filterTipo === 'medicoes') {
            $acoes = collect();
            $leituras = $leiturasQuery->get();
        } elseif ($this->filterTipo === 'acoes') {
            $leituras = collect();
            $acoes = $acoesQuery->get();
        } else {
            $leituras = $leiturasQuery->get();
            $acoes = $acoesQuery->get();
        }

        $events = collect();

        foreach ($leituras as $r) {
            $events->push([
                'tipo_evento' => 'medicao',
                'id' => $r->id,
                'timestamp' => $r->registado_em,
                'pool_id' => $r->pool_id,
                'piscina_nome' => $r->piscina?->name ?? 'Piscina',
                'instalacao_nome' => $r->piscina?->instalacao?->name ?? '',
                'autor' => $r->utilizador?->name ?? 'Técnico / NS',
                'ph' => $r->ph_efetivo,
                'cloro_livre' => $r->cloro_livre_efetivo,
                'cloro_total' => $r->cloro_total_efetivo,
                'cloro_combinado' => $r->cloro_combinado,
                'temperatura' => $r->temperatura_efetivo,
                'orp' => $r->orp,
                'banhistas' => $r->banhistas,
                'e_correcao' => $r->e_correcao,
                'observacoes' => $r->observacoes,
                'record' => $r,
            ]);
        }

        foreach ($acoes as $a) {
            $events->push([
                'tipo_evento' => 'acao_tecnica',
                'id' => $a->id,
                'timestamp' => $a->registado_em,
                'pool_id' => $a->pool_id,
                'piscina_nome' => $a->piscina?->name ?? 'Piscina',
                'instalacao_nome' => $a->piscina?->instalacao?->name ?? '',
                'autor' => $a->utilizador?->name ?? 'Técnico',
                'sub_tipo' => $a->tipo,
                'sub_tipo_label' => OperationalAction::TIPOS[$a->tipo] ?? $a->tipo,
                'observacoes' => $a->observacoes,
                'dados' => $a->dados ?? [],
                'foto' => $a->foto,
                'record' => $a,
            ]);
        }

        return $events->sortByDesc('timestamp')->take(40)->values();
    }

    public function getAcoesTecnicas(): Collection
    {
        $user = auth()->user();
        $poolQuery = Pool::query()->where('active', true);
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolQuery->whereIn('pools.id', $user->piscinas()->pluck('pools.id'));
        }
        if ($this->filterPoolId) {
            $poolQuery->where('pools.id', $this->filterPoolId);
        }
        $poolIds = $poolQuery->pluck('id');

        return OperationalAction::query()
            ->whereIn('pool_id', $poolIds)
            ->with(['piscina.instalacao', 'utilizador'])
            ->latest('registado_em')
            ->take(50)
            ->get();
    }
}
