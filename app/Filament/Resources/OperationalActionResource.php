<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource\Pages;
use App\Models\DailyRecord;
use App\Models\DosingContainer;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\SensorOutage;
use App\Models\SensorReading;
use App\Services\LeituraArtefactoService;
use App\Services\SettingsService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class OperationalActionResource extends Resource
{
    protected static ?string $model = OperationalAction::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Ações Operacionais';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Ação Operacional';

    protected static ?string $pluralModelLabel = 'Ações Operacionais';

    public static function canAccess(): bool
    {
        return self::userCanManageOperationalActions();
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['piscina.instalacao', 'utilizador']);

        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('pool_id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return self::userCanManageOperationalActions();
    }

    private static function userCanManageOperationalActions(): bool
    {
        $user = auth()->user();

        if ($user?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            return true;
        }

        return $user?->hasRole(UserRole::NADADOR_SALVADOR)
            && $user->podeVer(NSPermission::ANALISE_PARAMETROS);
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();

        if ($user?->hasRole(UserRole::ADMIN)) {
            return true;
        }

        // Quem registou corrige o próprio engano no mesmo dia (ex: 1200 m³ em vez
        // de 120). Passadas 24h fica só o admin, para não reescrever histórico.
        return $user?->hasRole(UserRole::TECNICO)
            && $record?->user_id === $user->id
            && $record->created_at?->gt(now()->subDay());
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['observacoes', 'piscina.name', 'tipo'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (OperationalAction::TIPOS[$record->tipo] ?? $record->tipo).' — '.$record->piscina->nome_completo;
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Piscina' => $record->piscina->nome_completo,
            'Quando' => $record->registado_em->format('d/m/Y H:i'),
        ];
    }

    private static function preencherOrpDaSonda(Get $get, Set $set): void
    {
        if ($get('tipo') !== OperationalAction::TIPO_ANALISE_PONTUAL) {
            return;
        }

        $poolId = $get('pool_id');
        $registadoEm = $get('registado_em');

        if (! $poolId || ! $registadoEm) {
            return;
        }

        $momento = Carbon::parse($registadoEm);
        $janelaMinutos = app(SettingsService::class)->getInt('sensor_fresco_minutos', 240);

        $leitura = SensorReading::query()
            ->where('pool_id', $poolId)
            ->whereNotNull('orp')
            ->whereBetween('lida_em', [
                $momento->clone()->subMinutes($janelaMinutos),
                $momento->clone()->addMinutes($janelaMinutos),
            ])
            ->get()
            // Com a sonda declarada em avaria (ou durante uma lavagem) o ORP dela
            // não representa a água: melhor campo vazio para input manual.
            ->reject(fn (SensorReading $r) => app(LeituraArtefactoService::class)->motivoEm((int) $poolId, $r->lida_em) !== null)
            ->sortBy(fn (SensorReading $r) => abs($r->lida_em->diffInSeconds($momento)))
            ->first();

        if ($leitura) {
            $set('dados.orp', (float) $leitura->orp);
            $set('dados.orp_da_sonda', true);
        } elseif ($get('dados.orp_da_sonda')) {
            $set('dados.orp', null);
            $set('dados.orp_da_sonda', false);
        }
    }

    /**
     * Preenche a quantidade a reabastecer com a capacidade total do bidão da
     * piscina/tipo selecionados — o caso mais comum é encher até ao topo.
     * Corre sempre que piscina ou tipo de bidão mudam (afterStateUpdated),
     * não como ->default() do campo: um default só é avaliado no mount do
     * formulário, antes de a piscina/tipo estarem escolhidos, pelo que nunca
     * teria valores para calcular a partir de.
     *
     * Em 'ambos' limpa sempre o campo: o Observer aplica a mesma quantidade a
     * ambos os bidões quando preenchida, por isso um valor residual de uma
     * seleção anterior (ex.: Cloro com 5L) aplicaria 5L também ao pH- em vez
     * de encher cada bidão até à sua própria capacidade.
     */
    private static function atualizarQuantidadeBidaoDefault(Get $get, Set $set): void
    {
        $tipo = $get('dados.bidao_tipo');

        if ($tipo === 'ambos') {
            $set('dados.quantidade_l', null);

            return;
        }

        $poolId = $get('pool_id');

        if (! $poolId || ! $tipo) {
            return;
        }

        $container = DosingContainer::where('pool_id', $poolId)
            ->where('tipo', $tipo)
            ->first();

        if ($container && $container->capacidade_ml) {
            $set('dados.quantidade_l', $container->capacidade_ml / 1000);
        }
    }

    private static function tiposDisponiveis(): array
    {
        $user = auth()->user();

        // Nadadores-salvadores (lifeguards) só podem registar análises pontuais.
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            return [
                OperationalAction::TIPO_ANALISE_PONTUAL => OperationalAction::TIPOS[OperationalAction::TIPO_ANALISE_PONTUAL],
            ];
        }

        return OperationalAction::TIPOS;
    }

    private static function piscinasOptions(): array
    {
        // Piscinas encerradas ficam na lista de propósito: é durante o
        // encerramento que se faz a drenagem, a obra e a lavagem dos filtros.
        // Só se marcam, para o técnico saber onde está a registar.
        return Pool::query()
            ->where('active', true)
            ->with(['instalacao', 'encerramentos'])
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Pool $p) => [
                $p->id => $p->nome_completo.($p->estaEncerradaEm() ? ' (encerrada)' : ''),
            ])
            ->toArray();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('user_id')->default(auth()->id()),

            Forms\Components\Select::make('pool_id')
                ->label('Piscina')
                ->options(self::piscinasOptions())
                ->default(fn () => request()->integer('pool')
                    ?: OperationalAction::query()
                        ->where('user_id', auth()->id())
                        ->orderByDesc('registado_em')
                        ->value('pool_id')
                    ?: DailyRecord::query()
                        ->where('user_id', auth()->id())
                        ->orderByDesc('registado_em')
                        ->value('pool_id'))
                ->searchable()
                ->visible(fn (Get $get) => ! $get('reabastecer_todas_leiria'))
                ->required(fn (Get $get) => ! $get('reabastecer_todas_leiria'))
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) {
                    self::preencherOrpDaSonda($get, $set);
                    self::atualizarQuantidadeBidaoDefault($get, $set);
                }),

            Forms\Components\Select::make('tipo')
                ->label('Tipo de ação')
                ->options(fn () => self::tiposDisponiveis())
                ->default(fn () => request()->query('tipo') === OperationalAction::TIPO_ANALISE_PONTUAL
                    ? OperationalAction::TIPO_ANALISE_PONTUAL
                    : (in_array(request()->query('tipo'), array_keys(self::tiposDisponiveis()), true) ? request()->query('tipo') : null))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::preencherOrpDaSonda($get, $set)),

            Forms\Components\Checkbox::make('reabastecer_todas_leiria')
                ->label('Aplicar às 3 piscinas de Leiria (Competição, Lazer, Infantil)')
                ->helperText('Cria um registo de reabastecimento igual para cada uma das 3 piscinas de Leiria.')
                ->dehydrated(false)
                ->live()
                ->visible(fn (Get $get, $livewire) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO
                    && $livewire instanceof CreateRecord),

            Forms\Components\DateTimePicker::make('registado_em')
                ->label('Data e hora')
                ->default(now())
                ->seconds(false)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Get $get, Set $set) => self::preencherOrpDaSonda($get, $set)),

            // Lavagem / enxaguamento de filtro.
            Forms\Components\TextInput::make('dados.filtro_nome')
                ->label('Identificação do filtro (opcional)')
                ->placeholder('Ex.: Filtro 1, Filtro Principal')
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            Forms\Components\TextInput::make('dados.duracao_min')
                ->label('Duração (min)')
                ->numeric()->minValue(0)->step(1)
                ->extraInputAttributes(['inputmode' => 'numeric'])
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            Forms\Components\TextInput::make('dados.pressao_antes_bar')
                ->label('Pressão inicial (bar)')
                ->numeric()->minValue(0)->step(0.01)
                ->extraInputAttributes(['inputmode' => 'decimal'])
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            Forms\Components\TextInput::make('dados.pressao_depois_bar')
                ->label('Pressão final (bar)')
                ->numeric()->minValue(0)->step(0.01)
                ->extraInputAttributes(['inputmode' => 'decimal'])
                ->visible(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_LAVAGEM_FILTRO,
                    OperationalAction::TIPO_ENXAGUAMENTO_FILTRO,
                ], true)),

            // Torneira / entrada de água.
            Forms\Components\Select::make('dados.agua_modo')
                ->label('Estado da água')
                ->options([
                    'auto_com_agua' => 'Auto com água',
                    'auto_sem_agua' => 'Auto sem água',
                    'on_com_agua' => 'ON com água',
                    'on_sem_agua' => 'ON sem água',
                    'off' => 'OFF sem água',
                ])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TORNEIRA)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TORNEIRA),

            // Bomba.
            Forms\Components\TextInput::make('dados.bomba_nome')
                ->label('Identificação da bomba (opcional)')
                ->placeholder('Ex.: Bomba 1, Recirculação')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_BOMBA),

            Forms\Components\Select::make('dados.bomba_acao')
                ->label('Ação realizada')
                ->options([
                    'ferragem' => 'Ferragem de bomba',
                    'limpeza_pre_filtro' => 'Limpeza de pré-filtro',
                    'paragem_arranque' => 'Paragem / Arranque',
                    'manutencao' => 'Manutenção / Reparação',
                ])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_BOMBA),

            Forms\Components\Toggle::make('dados.bomba_ferrada')
                ->label('Bomba ferrada')
                ->default(true)
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_BOMBA),

            // Contador.
            Forms\Components\TextInput::make('dados.contador_valor')
                ->label('Leitura do contador (m³)')
                ->numeric()->minValue(0)->step(0.01)
                ->extraInputAttributes(['inputmode' => 'decimal'])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_CONTADOR)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_CONTADOR),

            // Tanque.
            Forms\Components\TextInput::make('dados.tanque_nome')
                ->label('Identificação do tanque')
                ->placeholder('Ex.: Tanque de Compensação')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE),

            Forms\Components\TextInput::make('dados.tanque_nivel_pct')
                ->label('Nível estimado (%)')
                ->numeric()->minValue(0)->maxValue(100)->step(1)
                ->extraInputAttributes(['inputmode' => 'numeric'])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE),

            Forms\Components\Toggle::make('dados.tanque_ok')
                ->label('Tanque OK')
                ->default(true)
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TANQUE),

            // Reabastecimento de bidão.
            Forms\Components\Select::make('dados.bidao_tipo')
                ->label('Tipo de bidão')
                ->options([
                    DosingContainer::TIPO_CLORO => 'Cloro',
                    DosingContainer::TIPO_PH_MENOS => 'pH-',
                    'ambos' => 'Ambos (Cloro e pH-)',
                ])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::atualizarQuantidadeBidaoDefault($get, $set)),

            Forms\Components\TextInput::make('dados.quantidade_l')
                ->label('Quantidade reabastecida (L)')
                ->numeric()
                ->step('any')
                ->minValue(0)
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_REABASTECIMENTO_BIDAO && $get('dados.bidao_tipo') !== 'ambos')
                ->helperText(fn (Get $get) => $get('dados.bidao_tipo') === 'ambos'
                    ? 'Deixe em branco para encher ambos os bidões até às respetivas capacidades totais.'
                    : 'Por defeito, assume o tamanho total (capacidade) configurado para o bidão desta piscina.'),

            // Sonda: avaria / indisponibilidade.
            Forms\Components\Select::make('dados.sonda_estado')
                ->label('Situação da sonda')
                ->options(SensorOutage::MOTIVOS + [
                    SensorOutage::ESTADO_RESOLVIDO => 'Resolvido — sonda a dar valores fiáveis',
                ])
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_AVARIA_SONDA)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_AVARIA_SONDA)
                ->live()
                ->helperText(function (Get $get) {
                    if ((int) $get('pool_id') === 0) {
                        return 'Escolha a piscina para ver o estado atual da sonda.';
                    }

                    $aberta = SensorOutage::abertaPara((int) $get('pool_id'));

                    return $aberta === null
                        ? 'Sem avaria reportada nesta sonda. Ao guardar, os valores do controlador deixam de contar para a conformidade até a situação ser dada como resolvida.'
                        : 'Já existe uma avaria em aberto ('.mb_strtolower($aberta->motivoLabel()).', desde '
                            .$aberta->aberta_em->format('d/m/Y H:i').'). Guardar atualiza o motivo; escolha "Resolvido" para a fechar.';
                }),

            // Tratamento de choque.
            Forms\Components\TextInput::make('dados.produto')
                ->label('Produto utilizado')
                ->placeholder('Ex.: Hipoclorito de Cálcio / Cloro Choque')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE),

            Forms\Components\TextInput::make('dados.quantidade')
                ->label('Quantidade / Dose')
                ->placeholder('Ex.: 5 kg, 10 L')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE)
                ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_TRATAMENTO_CHOQUE),

            // Análise rápida (parcial): pelo menos um parâmetro.
            Forms\Components\Fieldset::make('Valores medidos')
                ->visible(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_ANALISE_PONTUAL)
                ->schema([
                    Forms\Components\TextInput::make('dados.ph')
                        ->label('pH')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal'])
                        ->required(fn (Get $get) => $get('tipo') === OperationalAction::TIPO_ANALISE_PONTUAL
                            && ! filled($get('dados.cloro_livre'))
                            && ! filled($get('dados.cloro_total'))
                            && ! filled($get('dados.orp'))
                            && ! filled($get('dados.temperatura'))
                        )
                        ->validationMessages([
                            'required' => 'Preencha pelo menos um valor na análise rápida.',
                        ]),
                    Forms\Components\TextInput::make('dados.cloro_livre')
                        ->label('Cl livre (mg/L)')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                    Forms\Components\TextInput::make('dados.cloro_total')
                        ->label('Cl total (mg/L)')->numeric()->step(0.01)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                    Forms\Components\TextInput::make('dados.orp')
                        ->label('ORP (mV)')->numeric()->step(1)
                        ->extraInputAttributes(['inputmode' => 'numeric'])
                        ->readOnly(fn (Get $get) => $get('dados.orp_da_sonda') === true)
                        ->helperText(fn (Get $get) => $get('dados.orp_da_sonda') === true
                            ? 'Preenchido automaticamente pela sonda (hora mais próxima da colheita).'
                            : 'Sem leitura da sonda perto desta hora — pode inserir manualmente.'),
                    Forms\Components\TextInput::make('dados.temperatura')
                        ->label('Temp (°C)')->numeric()->step(0.1)
                        ->extraInputAttributes(['inputmode' => 'decimal']),
                    Forms\Components\Hidden::make('dados.orp_da_sonda'),
                ])->columns(['default' => 2, 'sm' => 5]),

            Forms\Components\Textarea::make('observacoes')
                ->label('Observações')
                ->rows(3)
                ->required(fn (Get $get) => in_array($get('tipo'), [
                    OperationalAction::TIPO_OUTRO,
                    OperationalAction::TIPO_LIMPEZA_PRAIAS,
                    OperationalAction::TIPO_ASPIRACAO_FUNDO,
                    OperationalAction::TIPO_MANUTENCAO_EQUIPAMENTO,
                ], true)
                    // "Outro motivo" sem descrição deixaria a sonda marcada como
                    // indisponível em todos os ecrãs sem dizer porquê.
                    || ($get('tipo') === OperationalAction::TIPO_AVARIA_SONDA
                        && $get('dados.sonda_estado') === SensorOutage::MOTIVO_OUTRO))
                ->helperText(fn (Get $get) => match ($get('tipo')) {
                    OperationalAction::TIPO_AVARIA_SONDA => 'Descreva a situação (ex.: sensor de pH partido, assistência pedida ao fornecedor). Aparece em todos os ecrãs que mostram a sonda.',
                    OperationalAction::TIPO_OUTRO => 'Descreva detalhadamente a ação realizada.',
                    OperationalAction::TIPO_LIMPEZA_PRAIAS => 'Especifique as zonas limpas ou desinfetadas.',
                    OperationalAction::TIPO_ASPIRACAO_FUNDO => 'Indique se usou robô ou aspiração manual.',
                    OperationalAction::TIPO_MANUTENCAO_EQUIPAMENTO => 'Descreva o equipamento e o trabalho efetuado.',
                    default => null,
                })
                ->columnSpanFull(),

            Forms\Components\FileUpload::make('foto')
                ->label('Foto (opcional)')
                ->disk(DailyRecord::getStorageDisk())->visibility('private')
                ->directory('operational-actions')
                ->image()
                ->imageEditor()
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth('1024')
                ->maxSize(20480)
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                ->columnSpanFull(),
        ])->columns(2);
    }

    private static function tipoIcone(string $tipo): string
    {
        return match ($tipo) {
            OperationalAction::TIPO_LAVAGEM_FILTRO, OperationalAction::TIPO_ENXAGUAMENTO_FILTRO => 'heroicon-o-arrow-path',
            OperationalAction::TIPO_TORNEIRA => 'heroicon-o-cloud',
            OperationalAction::TIPO_BOMBA => 'heroicon-o-cog-6-tooth',
            OperationalAction::TIPO_CONTADOR => 'heroicon-o-calculator',
            OperationalAction::TIPO_TANQUE => 'heroicon-o-beaker',
            OperationalAction::TIPO_ANALISE_PONTUAL => 'heroicon-o-clipboard-document-check',
            OperationalAction::TIPO_REABASTECIMENTO_BIDAO => 'heroicon-o-archive-box',
            OperationalAction::TIPO_LIMPEZA_PRAIAS => 'heroicon-o-sparkles',
            OperationalAction::TIPO_ASPIRACAO_FUNDO => 'heroicon-o-arrow-down-circle',
            OperationalAction::TIPO_TRATAMENTO_CHOQUE => 'heroicon-o-bolt',
            OperationalAction::TIPO_MANUTENCAO_EQUIPAMENTO => 'heroicon-o-wrench-screwdriver',
            OperationalAction::TIPO_AVARIA_SONDA => 'heroicon-o-signal-slash',
            default => 'heroicon-o-ellipsis-horizontal-circle',
        };
    }

    private static function tipoCor(string $tipo): string
    {
        return match ($tipo) {
            OperationalAction::TIPO_TORNEIRA => 'warning',
            OperationalAction::TIPO_TRATAMENTO_CHOQUE => 'danger',
            OperationalAction::TIPO_AVARIA_SONDA => 'warning',
            OperationalAction::TIPO_ANALISE_PONTUAL => 'success',
            OperationalAction::TIPO_REABASTECIMENTO_BIDAO => 'info',
            default => 'gray',
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\TextEntry::make('tipo')
                        ->label('Ação')
                        ->badge()
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                        ->icon(fn (OperationalAction $record) => self::tipoIcone($record->tipo))
                        ->color(fn (OperationalAction $record) => self::tipoCor($record->tipo))
                        ->formatStateUsing(fn (string $state) => OperationalAction::TIPOS[$state] ?? $state),
                    Infolists\Components\TextEntry::make('registado_em')
                        ->label('Data e hora')
                        ->icon('heroicon-o-clock')
                        ->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('piscina.nome_completo')
                        ->label('Piscina')
                        ->icon('heroicon-o-map-pin'),
                    Infolists\Components\TextEntry::make('utilizador.name')
                        ->label('Responsável')
                        ->icon('heroicon-o-user'),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Valores registados')
                ->icon('heroicon-o-clipboard-document-list')
                ->schema([
                    Infolists\Components\TextEntry::make('valores')
                        ->hiddenLabel()
                        ->getStateUsing(fn (OperationalAction $record) => $record->dadosFormatados())
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Observações')
                ->icon('heroicon-o-chat-bubble-left-right')
                ->visible(fn ($record) => filled($record->observacoes))
                ->schema([
                    Infolists\Components\TextEntry::make('observacoes')
                        ->hiddenLabel()
                        ->columnSpanFull(),
                ]),

            Infolists\Components\Section::make('Foto')
                ->icon('heroicon-o-camera')
                ->visible(fn ($record) => filled($record->foto))
                ->schema([
                    Infolists\Components\ImageEntry::make('foto')
                        ->hiddenLabel()
                        ->disk(DailyRecord::getStorageDisk())
                        ->size(320)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->defaultSort('registado_em', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('registado_em')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('piscina.nome_completo')
                    ->label('Piscina')
                    ->searchable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Ação')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => OperationalAction::TIPOS[$state] ?? $state)
                    // O resumo passa a viver debaixo do tipo: em telemóvel a coluna
                    // "Valores" ficava fora do ecrã, alcançável só por swipe.
                    ->description(fn (OperationalAction $record): ?string => $record->dadosFormatados() ?: null),
                Tables\Columns\TextColumn::make('valores')
                    ->label('Valores')
                    ->getStateUsing(fn (OperationalAction $record) => $record->dadosFormatados())
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Responsável')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('observacoes')
                    ->label('Observações')
                    ->searchable()
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('foto')
                    ->label('Foto')
                    ->icon('heroicon-o-camera')
                    ->boolean(fn ($record) => filled($record->foto))
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tipo')
                    ->label('Tipo')
                    ->options(OperationalAction::TIPOS),
                Tables\Filters\SelectFilter::make('pool_id')
                    ->label('Piscina')
                    ->options(fn () => self::piscinasOptions()),
                Tables\Filters\Filter::make('registado_em')
                    ->form([
                        Forms\Components\DatePicker::make('data_de')->label('Desde'),
                        Forms\Components\DatePicker::make('data_ate')->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['data_de'] ?? null, fn (Builder $query, $date) => $query->whereDate('registado_em', '>=', $date))
                            ->when($data['data_ate'] ?? null, fn (Builder $query, $date) => $query->whereDate('registado_em', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperationalActions::route('/'),
            'create' => Pages\CreateOperationalAction::route('/create'),
            'view' => Pages\ViewOperationalAction::route('/{record}'),
            'edit' => Pages\EditOperationalAction::route('/{record}/edit'),
        ];
    }
}
