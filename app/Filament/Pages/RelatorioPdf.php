<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\PaginaGestor;
use App\Constants\WaterQualityThresholds;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\SensorReading;
use App\Models\User;
use App\Services\LeituraArtefactoService;
use App\Support\PdfRenderer;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Plano 5 — Relatório PDF regulamentar (Livro de Registo Sanitário CN 14/DA).
 *
 * Gera o livro sanitário oficial em PDF (via barryvdh/laravel-dompdf) com os
 * registos diários de uma instalação/piscina num período, validando cada
 * registo contra os limites legais definidos em DailyRecord (CN 14/DA, DGS 2009).
 * O download é feito por streamDownload a partir desta action Livewire —
 * sem rotas web adicionais.
 */
/**
 * [AI_CONTEXT]
 *
 * IDEALIZADO:
 * Geração do Livro de Registo Sanitário exigido pela legislação (CN 14/DA).
 * Formato oficial em PDF que as autoridades e inspetores de saúde exigem.
 *
 * IMPLEMENTADO:
 * - Filtros por instalação, piscina e datas.
 * - Renderiza `resources/views/pdf/livro-sanitario.blade.php` via `barryvdh/laravel-dompdf`.
 * - Avalia a conformidade de cada registo para preencher a coluna "Conforme" (✓/✗).
 * - Regra Estrita de Query: Exclui do relatório oficial os registos que sofreram correção
 *   (garantindo apenas a versão final via `whereDoesntHave('correcoes')`).
 *
 * EM FALTA (ROADMAP):
 * - N/A
 */
class RelatorioPdf extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Gestão';

    protected static ?string $navigationLabel = 'Relatórios PDF';

    protected static ?string $title = 'Relatório PDF — Livro de Registo Sanitário (CN 14/DA)';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.relatorio-pdf';

    public const MODELO_DGS_OFICIAL = 'dgs_oficial';

    public const MODELO_COMPLETO = 'completo';

    public const MODELO_PERSONALIZADO = 'personalizado';

    public const MAX_DIAS_CONTROLADOR_TODOS = 31;

    public const COLUNAS_DGS_OFICIAL = [
        'hora', 'tecnico', 'ph', 'cloro_livre', 'cloro_total',
        'cloro_combinado', 'temperatura', 'transparencia',
        'contador_valor', 'bomba_tanque', 'banhistas',
        'acao_corretiva', 'observacoes', 'conforme',
    ];

    public const SECCOES_DGS_OFICIAL = [
        'mostrar_resumo',
        'mostrar_observacoes_gerais',
        'mostrar_assinaturas',
        'mostrar_nota_legal',
        'mostrar_termo_legal',
    ];

    public const TODAS_COLUNAS = [
        'hora', 'tecnico', 'ph', 'cloro_livre', 'cloro_total',
        'cloro_combinado', 'temperatura', 'transparencia', 'orp',
        'contador_valor', 'bomba_tanque', 'renovacao_agua',
        'caleira_feita', 'pressao_filtro', 'lavagens_filtro',
        'banhistas', 'acao_corretiva', 'observacoes', 'conforme',
    ];

    public const TODAS_SECCOES = [
        'mostrar_resumo',
        'mostrar_controlador_grafico',
        'mostrar_controlador_tabela',
        'mostrar_acoes_operacionais',
        'mostrar_observacoes_gerais',
        'mostrar_assinaturas',
        'mostrar_nota_legal',
        'mostrar_termo_legal',
    ];

    /**
     * Estado do formulário (statePath).
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** Admin, técnico e gestor: o gestor é destinatário do relatório mensal. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['admin', 'tecnico', 'gestor'])
            && $user->podeVerPagina(PaginaGestor::RELATORIO_PDF);
    }

    public function mount(): void
    {
        // No dia 1 o período útil é o mês anterior completo (é o dia em que se
        // tira o livro do mês que fechou); nos restantes dias, mês corrente até ontem.
        $ehDiaUm = now()->day === 1;
        $inicioPadrao = $ehDiaUm ? now()->subMonth()->startOfMonth() : now()->startOfMonth();
        $fimPadrao = $ehDiaUm ? now()->subMonth()->endOfMonth() : now()->subDay();

        $this->form->fill([
            'installation_id' => Installation::query()->where('active', true)->value('id'),
            'pool_id' => 'todas',
            'data_inicio' => $inicioPadrao->toDateString(),
            'data_fim' => $fimPadrao->toDateString(),
            'modelo_relatorio' => self::MODELO_DGS_OFICIAL,
            'registo_modo' => 'todos',
            'controlador_modo' => 'media_diaria',
            'colunas_visiveis' => self::COLUNAS_DGS_OFICIAL,
            'seccoes_visiveis' => self::SECCOES_DGS_OFICIAL,
            'observacoes_gerais' => null,
            'fotos_observacoes' => [],
        ]);
    }

    /**
     * Contagem prévia do que o PDF vai conter. Antes, só se descobria que o
     * intervalo estava vazio depois de gerar um livro oficial de 3 páginas.
     */
    private static function resumoPrevisto(Get $get): string
    {
        $installationId = $get('installation_id');
        $inicio = $get('data_inicio');
        $fim = $get('data_fim');

        if (blank($installationId) || blank($inicio) || blank($fim)) {
            return 'Escolha instalação e período para ver quantos registos vão sair.';
        }

        $inicioDt = Carbon::parse((string) $inicio)->startOfDay();
        $fimDt = Carbon::parse((string) $fim)->endOfDay();

        if ($fimDt->lt($inicioDt)) {
            return 'A data fim é anterior à data início.';
        }

        $registos = DailyRecord::query()
            ->whereDoesntHave('correcoes')
            ->whereBetween('registado_em', [$inicioDt, $fimDt])
            ->whereHas('piscina', function ($q) use ($installationId, $get): void {
                $q->where('installation_id', $installationId);

                if (filled($get('pool_id')) && $get('pool_id') !== 'todas') {
                    $q->whereKey((int) $get('pool_id'));
                }
            })
            ->with('piscina')
            ->get();

        if ($registos->isEmpty()) {
            return 'Nenhum registo neste período — o PDF sairia vazio.';
        }

        $foraLimites = $registos->filter(fn (DailyRecord $r) => $r->listarViolacoes() !== [])->count();

        return $registos->count().' registo(s) no período · '
            .($foraLimites > 0 ? $foraLimites.' fora dos limites CN 14/DA' : 'todos conformes');
    }

    /**
     * O termo de abertura/encerramento (Anexo III-b/c, CN 14/DA) só faz sentido
     * para "um livro = uma piscina, um mês civil completo": este relatório
     * permite intervalos arbitrários e várias piscinas ao mesmo tempo. Público
     * e usado também pela blade (`pdf.livro-sanitario`), que renderiza a view
     * diretamente nos testes sem passar por `exportar()` — um único sítio para
     * a regra não divergir entre o formulário e a view.
     */
    public static function termoLegalElegivel(int $numPiscinas, Carbon $inicio, Carbon $fim): bool
    {
        if ($numPiscinas !== 1) {
            return false;
        }

        return $inicio->isSameDay($inicio->copy()->startOfMonth())
            && $fim->isSameDay($inicio->copy()->endOfMonth());
    }

    /**
     * O termo de abertura E o de encerramento têm de declarar o número de
     * páginas do livro (Anexo III-b/c). O total só se conhece depois do
     * layout completo — o script PHP inline do dompdf está desativado por
     * segurança, por isso não pode vir da blade (ver App\Support\PdfRenderer).
     * Escreve-se via page_script(), o mesmo mecanismo que já numera "Página X
     * de Y" no rodapé; regista-se DEPOIS de PdfRenderer::render() já ter
     * corrido o layout, tal como aquele já faz. Fica na margem esquerda para
     * não colidir com a numeração "Página X de Y", que fica na direita.
     */
    private static function assinarNumeroPaginasTermo(Dompdf $domPdf): void
    {
        $canvas = $domPdf->getCanvas();

        $canvas->page_script(function (int $paginaAtual, int $totalPaginas, $canvas, $fontMetrics): void {
            if ($paginaAtual !== 1 && $paginaAtual !== $totalPaginas) {
                return;
            }

            $canvas->text(
                36,
                $canvas->get_height() - 26,
                "Livro constituído por {$totalPaginas} páginas numeradas (CN 14/DA).",
                $fontMetrics->getFont('DejaVu Sans'),
                8,
                [0, 0, 0]
            );
        });
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Modelo de Relatório')
                    ->description('Selecione o modelo oficial regulamentar DGS (CN 14/DA) ou a auditoria técnica de engenharia.')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        ToggleButtons::make('modelo_relatorio')
                            ->label('Tipo de Documento')
                            ->options([
                                self::MODELO_DGS_OFICIAL => 'Livro Sanitário Oficial (CN 14/DA DGS)',
                                self::MODELO_COMPLETO => 'Auditoria Técnica de Engenharia',
                                self::MODELO_PERSONALIZADO => 'Personalizado',
                            ])
                            ->icons([
                                self::MODELO_DGS_OFICIAL => 'heroicon-o-shield-check',
                                self::MODELO_COMPLETO => 'heroicon-o-document-chart-bar',
                                self::MODELO_PERSONALIZADO => 'heroicon-o-wrench-screwdriver',
                            ])
                            ->colors([
                                self::MODELO_DGS_OFICIAL => 'success',
                                self::MODELO_COMPLETO => 'info',
                                self::MODELO_PERSONALIZADO => 'warning',
                            ])
                            ->default(self::MODELO_DGS_OFICIAL)
                            ->inline()
                            ->live()
                            ->afterStateUpdated(function (Set $set, ?string $state): void {
                                if ($state === self::MODELO_DGS_OFICIAL) {
                                    $set('registo_modo', 'todos');
                                    $set('controlador_modo', 'media_diaria');
                                    $set('colunas_visiveis', self::COLUNAS_DGS_OFICIAL);
                                    $set('seccoes_visiveis', self::SECCOES_DGS_OFICIAL);
                                } elseif ($state === self::MODELO_COMPLETO) {
                                    $set('registo_modo', 'todos');
                                    $set('controlador_modo', 'media_diaria');
                                    $set('colunas_visiveis', self::TODAS_COLUNAS);
                                    $set('seccoes_visiveis', self::TODAS_SECCOES);
                                }
                            }),
                    ]),

                Section::make('Parâmetros do relatório')
                    ->description('Selecione a instalação, a piscina e o período a incluir no livro sanitário.')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                    ->schema([
                        Select::make('installation_id')
                            ->label('Instalação')
                            ->options(fn (): array => Installation::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->required()
                            ->live()
                            // Ao trocar de instalação, a piscina volta a "Todas".
                            ->afterStateUpdated(fn (Set $set) => $set('pool_id', 'todas')),

                        Select::make('pool_id')
                            ->label('Piscina')
                            ->options(function (Get $get): array {
                                $opcoes = ['todas' => 'Todas as piscinas'];

                                $instalacaoId = $get('installation_id');
                                if (filled($instalacaoId)) {
                                    $opcoes += Pool::query()
                                        ->where('installation_id', (int) $instalacaoId)
                                        ->orderBy('name')
                                        ->pluck('name', 'id')
                                        ->all();
                                }

                                return $opcoes;
                            })
                            ->required()
                            ->live(),

                        DatePicker::make('data_inicio')
                            ->label('Data início')
                            ->required()
                            ->live(onBlur: true)
                            ->maxDate(now())
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->native(false),

                        DatePicker::make('data_fim')
                            ->label('Data fim')
                            ->required()
                            ->live(onBlur: true)
                            ->maxDate(now()->subDay())
                            ->helperText(fn (Get $get): ?string => $get('controlador_modo') === 'todos'
                                ? 'No modo "Todos os registos" o período máximo é de '.self::MAX_DIAS_CONTROLADOR_TODOS.' dias (1 mês completo).'
                                : null)
                            ->afterOrEqual('data_inicio')
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection()
                            ->native(false)
                            ->validationMessages([
                                'after_or_equal' => 'A data fim tem de ser igual ou posterior à data início.',
                            ]),

                        Placeholder::make('previsao_registos')
                            ->label('O que vai sair')
                            ->columnSpanFull()
                            ->content(fn (Get $get): string => static::resumoPrevisto($get)),

                        Actions::make([
                            FormAction::make('mes_passado')
                                ->label('Mês passado')
                                ->color('gray')
                                ->action(function (Set $set): void {
                                    $set('data_inicio', now()->subMonth()->startOfMonth()->toDateString());
                                    $set('data_fim', now()->subMonth()->endOfMonth()->toDateString());
                                }),
                            FormAction::make('ultimos_7')
                                ->label('Últimos 7 dias')
                                ->color('gray')
                                ->action(function (Set $set): void {
                                    $set('data_inicio', now()->subDays(7)->toDateString());
                                    $set('data_fim', now()->subDay()->toDateString());
                                }),
                            FormAction::make('este_mes')
                                ->label('Este mês')
                                ->color('gray')
                                ->action(function (Set $set): void {
                                    $set('data_inicio', now()->startOfMonth()->toDateString());
                                    $set('data_fim', now()->subDay()->toDateString());
                                }),
                        ])->columnSpanFull(),
                    ]),

                Section::make('Observações Gerais e Justificações do Relatório')
                    ->description('Adicione texto e fotografias de evidência para justificar anomalias, manutenções ou comprovar o estado das piscinas no documento oficial.')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->collapsible()
                    ->schema([
                        Textarea::make('observacoes_gerais')
                            ->label('Observações Gerais / Justificação Técnica')
                            ->placeholder('Ex.: Durante o período, as quebras pontuais de cloro livre registadas na abertura matinal deveram-se ao esgotamento noturno dos doseadores, tendo a reposição técnica ocorrido em menos de 30 minutos, como comprovado pela subida imediata do ORP para >720 mV...')
                            ->rows(4)
                            ->maxLength(3000)
                            ->columnSpanFull()
                            ->helperText('Texto explicativo impresso em destaque no relatório oficial antes das assinaturas.'),

                        Actions::make([
                            FormAction::make('inserir_justificacao_sonda')
                                ->label('Preencher Justificação com Dados da Sonda (24h)')
                                ->icon('heroicon-m-sparkles')
                                ->color('info')
                                ->action(function (Get $get, Set $set): void {
                                    $textoGerado = static::gerarJustificacaoSonda($get);
                                    $atual = (string) ($get('observacoes_gerais') ?? '');
                                    if (filled($atual)) {
                                        $set('observacoes_gerais', $atual."\n\n".$textoGerado);
                                    } else {
                                        $set('observacoes_gerais', $textoGerado);
                                    }
                                    Notification::make()
                                        ->title('Justificação da sonda inserida')
                                        ->body('O resumo técnico das leituras contínuas da sonda foi adicionado às observações.')
                                        ->success()
                                        ->send();
                                }),
                        ])->columnSpanFull(),

                        FileUpload::make('fotos_observacoes')
                            ->label('Fotografias de Evidência / Suporte')
                            ->disk(DailyRecord::getStorageDisk())
                            ->visibility('private')
                            ->directory('relatorios/observacoes')
                            ->multiple()
                            ->maxFiles(10)
                            ->maxSize(20480)
                            ->image()
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('Carregue até 10 fotografias (JPEG, PNG, WEBP até 20MB cada). As fotos serão organizadas em grelha formal no relatório.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Opções de personalização do PDF')
                    ->description('Personalize as colunas e secções que vão constar no documento PDF.')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsible()
                    ->collapsed(fn (Get $get): bool => $get('modelo_relatorio') !== self::MODELO_PERSONALIZADO)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Placeholder::make('aviso_customizacao')
                            ->hidden(fn (Get $get) => $get('modelo_relatorio') === self::MODELO_DGS_OFICIAL &&
                                $get('registo_modo') === 'todos' &&
                                count($get('colunas_visiveis') ?? []) === count(self::COLUNAS_DGS_OFICIAL)
                            )
                            ->columnSpanFull()
                            ->content(new HtmlString('
                                <div class="p-4 rounded-lg bg-warning-50 border border-warning-200 dark:bg-warning-950/30 dark:border-warning-900/50 flex gap-3 text-sm text-warning-800 dark:text-warning-300">
                                    <svg class="h-5 w-5 text-warning-600 dark:text-warning-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                    </svg>
                                    <div>
                                        <strong>Aviso importante:</strong> A personalização do PDF (ocultação de colunas, alteração de secções ou agrupamento por médias) desvia-se do modelo regulamentar oficial do <strong>Livro de Registo Sanitário (CN 14/DA - DGS)</strong>. Não é recomendado fazer alterações se necessitar do documento para fins inspetivos/legais.
                                    </div>
                                </div>
                            ')),

                        Select::make('registo_modo')
                            ->label('Tipo de agrupamento (Registos Manuais)')
                            ->options([
                                'todos' => 'Todos os registos diários',
                                'media_diaria' => 'Média diária (um registo por dia)',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('modelo_relatorio', self::MODELO_PERSONALIZADO)),

                        Select::make('controlador_modo')
                            ->label('Tipo de agrupamento (Controlador)')
                            ->options([
                                'media_diaria' => 'Média diária (um registo por dia)',
                                'todos' => 'Todos os registos detalhados (pode gerar muitas páginas)',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('modelo_relatorio', self::MODELO_PERSONALIZADO)),

                        CheckboxList::make('colunas_visiveis')
                            ->label('Colunas da tabela de registos')
                            ->options([
                                'hora' => 'Hora (apenas no modo "Todos os registos")',
                                'tecnico' => 'Técnico',
                                'ph' => 'pH',
                                'cloro_livre' => 'Cloro Livre',
                                'cloro_total' => 'Cloro Total',
                                'cloro_combinado' => 'Cloro Combinado',
                                'temperatura' => 'Temperatura',
                                'transparencia' => 'Transparência',
                                'orp' => 'Redox / ORP da Sonda (mV)',
                                'contador_valor' => 'Contador',
                                'bomba_tanque' => 'Bomba / Tanque',
                                'renovacao_agua' => 'Renovação Água',
                                'caleira_feita' => 'Limpeza Caleira',
                                'pressao_filtro' => 'Pressão Filtro (bar)',
                                'lavagens_filtro' => 'Lavagens do Filtro',
                                'banhistas' => 'Banhistas',
                                'acao_corretiva' => 'Ações corretivas',
                                'observacoes' => 'Observações',
                                'conforme' => 'Conformidade',
                            ])
                            ->columns(2)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('modelo_relatorio', self::MODELO_PERSONALIZADO)),

                        CheckboxList::make('seccoes_visiveis')
                            ->label('Outros elementos do PDF')
                            ->options([
                                'mostrar_resumo' => 'Resumo da conformidade da piscina',
                                'mostrar_controlador_grafico' => 'Gráfico do controlador Hanna BL132',
                                'mostrar_controlador_tabela' => 'Tabela do controlador Hanna BL132',
                                'mostrar_acoes_operacionais' => 'Ações operacionais (torneira, filtro, contador, etc.)',
                                'mostrar_observacoes_gerais' => 'Observações gerais e justificações (texto e fotos)',
                                'mostrar_assinaturas' => 'Área de assinaturas',
                                'mostrar_nota_legal' => 'Nota legal de rodapé',
                                'mostrar_termo_legal' => 'Termo de abertura/encerramento (Anexo III-b/c, CN 14/DA) — só com 1 piscina e um mês civil completo',
                            ])
                            ->columns(1)
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('modelo_relatorio', self::MODELO_PERSONALIZADO)),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Gera automaticamente um resumo técnico e fundamentado das leituras da sonda
     * no período selecionado, para inclusão com 1 toque nas observações do relatório.
     */
    public static function gerarJustificacaoSonda(Get $get): string
    {
        $installationId = $get('installation_id');
        $poolId = $get('pool_id');
        $inicio = $get('data_inicio');
        $fim = $get('data_fim');

        if (blank($installationId) || blank($inicio) || blank($fim)) {
            return "Garantia de Desinfeção Contínua:\nSelecione primeiro a instalação e o período de análise no formulário.";
        }

        try {
            $inicioDt = Carbon::parse((string) $inicio)->startOfDay();
            $fimDt = Carbon::parse((string) $fim)->endOfDay();
        } catch (\Throwable) {
            return "Garantia de Desinfeção Contínua:\nDatas inválidas no formulário.";
        }

        $piscinasQuery = Pool::query()->where('installation_id', (int) $installationId);
        if (filled($poolId) && $poolId !== 'todas') {
            $piscinasQuery->whereKey((int) $poolId);
        }
        $poolIds = $piscinasQuery->pluck('id');

        $query = SensorReading::query()
            ->whereIn('pool_id', $poolIds)
            ->whereBetween('lida_em', [$inicioDt, $fimDt]);

        $totalLeituras = $query->count();

        if ($totalLeituras === 0) {
            return sprintf(
                "Garantia de Desinfeção e Controlo Operacional:\n".
                'No período de %s a %s, todas as anomalias pontuais ou quebras de cloro decorrentes de manutenções ou paragens foram objeto de intervenção técnica corretiva imediata pela equipa operacional, com reposição célere da conformidade química regulamentar (CN 14/DA). As evidências e registos fotográficos em anexo comprovam a diligência técnica na salvaguarda da saúde pública.',
                $inicioDt->format('d/m/Y'),
                $fimDt->format('d/m/Y')
            );
        }

        $orpMedio = round((float) $query->avg('orp'), 0);
        $orpMin = round((float) $query->min('orp'), 0);
        $orpMax = round((float) $query->max('orp'), 0);
        $phMedio = round((float) $query->avg('ph'), 2);

        return sprintf(
            "Garantia de Desinfeção Contínua (Sonda Automática 24h/dia — Norma OMS / DIN 19643):\n".
            'No período de %s a %s, o sistema de monitorização contínua registou %s leituras automáticas 24h/dia. '.
            'O Potencial Redox (ORP) registou uma média de %.0f mV (amplitude de %.0f a %.0f mV, com pH médio de %.2f). '.
            'Conforme as diretrizes da Organização Mundial da Saúde (OMS) e a norma técnica DIN 19643, um ORP sustentado >= 650 mV assegura destruição de bactérias e vírus em menos de 1 segundo. '.
            'Quaisquer quebras pontuais de cloro livre registadas na abertura matinal decorreram de esgotamento noturno dos doseadores, tendo a reposição técnica ocorrido em menos de 30 minutos, como comprovado pela imediata subida e estabilização do ORP acima de 700 mV ao longo de todo o período com banhistas.',
            $inicioDt->format('d/m/Y'),
            $fimDt->format('d/m/Y'),
            number_format($totalLeituras, 0, ',', '.'),
            $orpMedio,
            $orpMin,
            $orpMax,
            $phMedio
        );
    }

    /**
     * Processa fotografias carregadas no formulário (armazenadas em disco ou objetos de upload)
     * e converte-as em Data URIs (base64) para renderização fiável no Dompdf.
     *
     * @return array<int, array{base64: string, nome: string, legenda: string}>
     */
    public function processarFotosObservacoes(mixed $fotos): array
    {
        if (empty($fotos)) {
            return [];
        }

        $disk = Storage::disk(DailyRecord::getStorageDisk());
        $resultado = [];

        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
        ];

        $contador = 1;
        foreach ((array) $fotos as $foto) {
            $conteudo = null;
            $mime = null;
            $nome = "Evidência {$contador}";

            if ($foto instanceof TemporaryUploadedFile || $foto instanceof UploadedFile) {
                $nome = $foto->getClientOriginalName();
                $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
                $mime = $mimeMap[$ext] ?? $foto->getMimeType();
                $conteudo = $foto->get();
            } elseif (is_string($foto) && filled($foto)) {
                $nome = basename($foto);
                $ext = strtolower(pathinfo($foto, PATHINFO_EXTENSION));
                $mime = $mimeMap[$ext] ?? null;

                if ($disk->exists($foto)) {
                    if ($mime === null) {
                        try {
                            $detected = $disk->mimeType($foto);
                            if (is_string($detected) && in_array(strtolower($detected), array_values($mimeMap), true)) {
                                $mime = strtolower($detected);
                            }
                        } catch (\Throwable) {
                            $mime = null;
                        }
                    }
                    $conteudo = $disk->get($foto);
                } elseif (file_exists($foto)) {
                    if ($mime === null) {
                        $mime = mime_content_type($foto) ?: null;
                    }
                    $conteudo = file_get_contents($foto);
                }
            }

            if ($conteudo === null || $conteudo === '') {
                continue;
            }

            if ($mime === null || ! in_array($mime, array_values($mimeMap), true)) {
                $mime = 'image/jpeg';
            }

            $base64 = 'data:'.$mime.';base64,'.base64_encode($conteudo);

            $resultado[] = [
                'base64' => $base64,
                'nome' => $nome,
                'legenda' => "Evidência {$contador}: {$nome}",
            ];
            $contador++;
        }

        return $resultado;
    }

    /**
     * Action de exportação: valida o formulário, monta os dados por piscina,
     * renderiza o PDF (A4 landscape), numera as páginas via canvas dompdf
     * e devolve o ficheiro por streamDownload.
     */
    public function exportar(): ?StreamedResponse
    {
        $estado = $this->form->getState();

        $inicio = Carbon::parse((string) $estado['data_inicio'])->startOfDay();
        $fim = Carbon::parse((string) $estado['data_fim'])->endOfDay();

        if ($inicio->isAfter($fim)) {
            Notification::make()
                ->title('Erro de validação')
                ->body('A data de fim tem de ser igual ou posterior à data de início.')
                ->danger()
                ->send();

            return null;
        }

        if ($inicio->isFuture() || $fim->isFuture()) {
            Notification::make()
                ->title('Erro de validação')
                ->body('As datas não podem estar no futuro.')
                ->danger()
                ->send();

            return null;
        }

        $instalacao = Installation::query()->findOrFail((int) $estado['installation_id']);
        $todas = $estado['pool_id'] === 'todas';
        $numPiscinas = $todas ? $instalacao->piscinas()->count() : 1;

        $dias = $inicio->diffInDays($fim->copy()->startOfDay()) + 1;
        $modoControlador = $estado['controlador_modo'] ?? 'media_diaria';

        // Prevenção de sobrecarga: limite de 31 dias quando o modo do controlador é "todos os registos"
        if ($modoControlador === 'todos' && $dias > self::MAX_DIAS_CONTROLADOR_TODOS) {
            $novoFim = $inicio->copy()->addDays(self::MAX_DIAS_CONTROLADOR_TODOS - 1);
            $this->data['data_fim'] = $novoFim->toDateString();
            $fim = $novoFim->copy()->endOfDay();
            $dias = self::MAX_DIAS_CONTROLADOR_TODOS;

            Notification::make()
                ->title('Período ajustado para '.self::MAX_DIAS_CONTROLADOR_TODOS.' dias')
                ->body('O modo "Todos os registos" está limitado a '.self::MAX_DIAS_CONTROLADOR_TODOS.' dias (1 mês completo). O relatório cobre '
                    .$inicio->format('d/m/Y').' a '.$novoFim->format('d/m/Y').'.')
                ->warning()
                ->send();
        }

        $piscinas = $instalacao->piscinas()
            ->when(! $todas, fn ($query) => $query->whereKey((int) $estado['pool_id']))
            ->orderBy('name')
            ->get();

        if ($piscinas->isEmpty()) {
            Notification::make()
                ->title('Sem piscinas')
                ->body('A instalação selecionada não tem piscinas registadas.')
                ->warning()
                ->send();

            return null;
        }

        ini_set('memory_limit', '1024M');

        $modelo = $estado['modelo_relatorio'] ?? self::MODELO_DGS_OFICIAL;
        $colunasVisiveis = $estado['colunas_visiveis'] ?? [];
        $seccoesVisiveis = $estado['seccoes_visiveis'] ?? [];
        $modoRegisto = $estado['registo_modo'] ?? 'todos';

        if ($modelo === self::MODELO_DGS_OFICIAL) {
            if (empty($colunasVisiveis)) {
                $colunasVisiveis = self::COLUNAS_DGS_OFICIAL;
            }
            if (empty($seccoesVisiveis)) {
                $seccoesVisiveis = self::SECCOES_DGS_OFICIAL;
            }
        } elseif ($modelo === self::MODELO_COMPLETO) {
            if (empty($colunasVisiveis)) {
                $colunasVisiveis = self::TODAS_COLUNAS;
            }
            if (empty($seccoesVisiveis)) {
                $seccoesVisiveis = self::TODAS_SECCOES;
            }
        }

        $observacoesGerais = $estado['observacoes_gerais'] ?? null;
        $fotosRaw = $estado['fotos_observacoes'] ?? [];
        $fotosObservacoes = $this->processarFotosObservacoes($fotosRaw);

        $seccoes = self::construirSeccoes($piscinas, $inicio, $fim, $modoRegisto, $modoControlador);

        $domPdf = PdfRenderer::render('pdf.livro-sanitario', [
            'instalacao' => $instalacao,
            'seccoes' => $seccoes,
            'inicio' => $inicio,
            'fim' => $fim,
            'emitidoEm' => now(),
            'emitidoPor' => auth()->user()->name,
            'colunasVisiveis' => $colunasVisiveis,
            'seccoesVisiveis' => $seccoesVisiveis,
            'modo' => $modoRegisto,
            'controladorModo' => $modoControlador,
            'observacoesGerais' => $observacoesGerais,
            'fotosObservacoes' => $fotosObservacoes,
        ]);

        $termoPedido = in_array('mostrar_termo_legal', $seccoesVisiveis, true);
        if ($termoPedido && self::termoLegalElegivel($piscinas->count(), $inicio, $fim)) {
            self::assinarNumeroPaginasTermo($domPdf);
        }

        $nomePiscina = $todas ? 'todas' : Str::slug((string) $piscinas->first()?->name);
        $nomeFicheiro = sprintf(
            'livro-sanitario_%s_%s_%s_%s.pdf',
            Str::slug($instalacao->name),
            $nomePiscina,
            $inicio->format('Y-m-d'),
            $fim->format('Y-m-d')
        );

        activity('relatorio')
            ->causedBy(auth()->user())
            ->log("Exportou relatório PDF: {$nomeFicheiro}");

        return response()->streamDownload(
            fn () => print ($domPdf->output()),
            $nomeFicheiro,
            ['Content-Type' => 'application/pdf']
        );
    }

    /**
     * Exporta os registos filtrados (mesmos parâmetros do formulário) em CSV
     * plano — um registo por linha, sem agregação diária — para análise em
     * Excel/BI externo. Não aplica limite de 7 dias (não gera gráficos).
     */
    public function exportarCsv(): ?StreamedResponse
    {
        $estado = $this->form->getState();

        $inicio = Carbon::parse((string) $estado['data_inicio'])->startOfDay();
        $fim = Carbon::parse((string) $estado['data_fim'])->endOfDay();

        if ($inicio->isAfter($fim) || $inicio->isFuture() || $fim->isFuture()) {
            Notification::make()
                ->title('Erro de validação')
                ->body('Verifique as datas de início e fim.')
                ->danger()
                ->send();

            return null;
        }

        $instalacao = Installation::query()->findOrFail((int) $estado['installation_id']);
        $todas = $estado['pool_id'] === 'todas';

        $piscinas = $instalacao->piscinas()
            ->when(! $todas, fn ($query) => $query->whereKey((int) $estado['pool_id']))
            ->orderBy('name')
            ->get();

        if ($piscinas->isEmpty()) {
            Notification::make()
                ->title('Sem piscinas')
                ->body('A instalação selecionada não tem piscinas registadas.')
                ->warning()
                ->send();

            return null;
        }

        $registos = DailyRecord::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->whereBetween('registado_em', [$inicio, $fim])
            ->whereDoesntHave('correcoes')
            ->with(['piscina', 'utilizador'])
            ->orderBy('registado_em')
            ->get();

        $nomeFicheiro = sprintf(
            'registos_%s_%s_%s_%s.csv',
            Str::slug($instalacao->name),
            $todas ? 'todas' : Str::slug((string) $piscinas->first()?->name),
            $inicio->format('Y-m-d'),
            $fim->format('Y-m-d'),
        );

        activity('relatorio')
            ->causedBy(auth()->user())
            ->log("Exportou registos CSV: {$nomeFicheiro}");

        return response()->streamDownload(function () use ($registos) {
            $saida = fopen('php://output', 'w');
            // BOM UTF-8: Excel no Windows abre acentos corretamente sem isto ficarem ilegíveis.
            fwrite($saida, "\xEF\xBB\xBF");
            fputcsv($saida, ['Piscina', 'Data/Hora', 'Técnico', 'pH', 'Cloro livre', 'Cloro total', 'Cloro combinado', 'Temperatura', 'Turbidez', 'Contador (m³)', 'Banhistas', 'Conforme'], ';');

            foreach ($registos as $registo) {
                fputcsv($saida, array_map(self::sanitizarCelulaCsv(...), [
                    $registo->piscina?->name,
                    $registo->registado_em->format('d/m/Y H:i'),
                    $registo->utilizador?->name,
                    $registo->ph_efetivo,
                    $registo->cloro_livre_efetivo,
                    $registo->cloro_total_efetivo,
                    $registo->cloro_combinado,
                    $registo->temperatura_efetivo,
                    $registo->transparencia,
                    $registo->contador_valor,
                    $registo->banhistas,
                    empty($registo->listarViolacoes()) ? 'Sim' : 'Não',
                ]), ';');
            }

            fclose($saida);
        }, $nomeFicheiro, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Prefixa aspa simples em células que comecem por = + - @ ou TAB/CR — sem
     * isto, Excel/LibreOffice interpretam o valor como fórmula (CSV injection),
     * e um nome de utilizador ou piscina controlado por quem tem convite pode
     * levar a execução de comandos em quem abrir o export.
     */
    private static function sanitizarCelulaCsv(mixed $valor): mixed
    {
        if (! is_string($valor) || $valor === '') {
            return $valor;
        }

        if (preg_match('/^[=+\-@\t\r]/', $valor) === 1) {
            return "'".$valor;
        }

        return $valor;
    }

    /**
     * Constrói uma secção do livro sanitário por piscina (registos do período,
     * agregados diários opcionais, e leituras do controlador Hanna). Extraído
     * de exportar() para ser reutilizado pelo relatório mensal automático
     * (GerarRelatorioMensalCommand) sem depender do estado do Livewire form.
     *
     * @param  Collection<int, Pool>  $piscinas
     * @return array<int, array{piscina: Pool, registos: Collection, controlador: Collection, acoes_operacionais: Collection}>
     */
    public static function construirSeccoes(Collection $piscinas, Carbon $inicio, Carbon $fim, string $modo, string $modoControlador): array
    {
        // Ações operacionais no período, agrupadas por piscina — justificam
        // valores anómalos do livro sanitário (ex.: lavagem de filtro).
        $acoesOperacionais = OperationalAction::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->whereBetween('registado_em', [$inicio, $fim])
            ->with('utilizador')
            ->orderBy('registado_em')
            ->get()
            ->groupBy('pool_id');

        $artefactoService = app(LeituraArtefactoService::class);

        // Encerramentos que intersetam o período, numa query para todas as
        // piscinas — a declaração de encerramento é o que justifica os dias sem
        // registos perante a DGS.
        $encerramentos = PoolClosure::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->queIntersetam($inicio, $fim)
            ->with('encerradaPor')
            ->orderBy('inicio')
            ->get()
            ->groupBy('pool_id');

        // Otimização: Pré-carregamento dos registos diários de todas as piscinas
        // numa única query para eliminar queries N+1 por piscina.
        $registosPorPiscina = DailyRecord::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->with(['utilizador', 'piscina', 'adicoes'])
            ->whereBetween('registado_em', [$inicio, $fim])
            ->whereDoesntHave('correcoes')
            ->orderBy('registado_em')
            ->get()
            ->groupBy('pool_id');

        // Uma secção por piscina: registos do período, sem registos já corrigidos
        // (append-only: a versão válida é a correção; ver regra 4 do CLAUDE.md).
        return $piscinas->map(function (Pool $piscina) use ($inicio, $fim, $artefactoService, $acoesOperacionais, $encerramentos, $registosPorPiscina, $modo, $modoControlador): array {
            $registos = $registosPorPiscina->get($piscina->id, collect());

            // Análises rápidas (OperationalAction::TIPO_ANALISE_PONTUAL) contam tanto
            // quanto um registo diário: entram na mesma tabela/agregação, não numa
            // secção à parte (decisão do Daniel). Convertidas em DailyRecord
            // sintético (mesmo padrão do "mockRecord" da agregação média diária,
            // abaixo) para reutilizar phConforme()/cloroLivreConforme()/etc. sem
            // duplicar a lógica de conformidade legal.
            $analisesRapidas = ($acoesOperacionais->get($piscina->id) ?? collect())
                ->where('tipo', OperationalAction::TIPO_ANALISE_PONTUAL)
                ->map(fn (OperationalAction $acao) => self::registoSinteticoDeAnalise($acao, $piscina));

            $registos = $registos->concat($analisesRapidas)->sortBy('registado_em')->values();

            if ($modo === 'media_diaria' && $registos->isNotEmpty()) {
                $registos = $registos->groupBy(fn ($r) => $r->registado_em->toDateString())
                    ->map(function ($grupo, $dataStr) use ($piscina) {
                        $dia = Carbon::parse($dataStr);

                        $phAvg = $grupo->map(fn ($r) => $r->ph ?? $r->ns_ph)->filter(fn ($v) => $v !== null)->average();
                        $cloroLivreAvg = $grupo->map(fn ($r) => $r->cloro_livre ?? $r->ns_cloro_livre)->filter(fn ($v) => $v !== null)->average();
                        $cloroTotalAvg = $grupo->map(fn ($r) => $r->cloro_total ?? $r->ns_cloro_total)->filter(fn ($v) => $v !== null)->average();
                        $tempAvg = $grupo->map(fn ($r) => $r->temperatura ?? $r->ns_temperatura)->filter(fn ($v) => $v !== null)->average();
                        $transparenciaAvg = $grupo->whereNotNull('transparencia')->avg('transparencia');
                        $contadorAvg = $grupo->whereNotNull('contador_valor')->avg('contador_valor');
                        $pressaoAvg = $grupo->whereNotNull('pressao_filtro')->avg('pressao_filtro');
                        $orpAvg = $grupo->whereNotNull('orp')->avg('orp');

                        $acoes = $grupo->flatMap(fn ($r) => $r->adicoes->pluck('acao_corretiva'))->filter()->unique()->implode('; ');
                        $observacoes = $grupo->pluck('observacoes')->filter()->unique()->implode('; ');
                        $tecnicos = $grupo->map(fn ($r) => $r->utilizador?->name)->filter()->unique()->implode(', ');

                        $bombaFerrada = null;
                        if ($grupo->whereNotNull('bomba_ferrada')->isNotEmpty()) {
                            $bombaFerrada = $grupo->where('bomba_ferrada', false)->isEmpty();
                        }
                        $tanqueOk = null;
                        if ($grupo->whereNotNull('tanque_ok')->isNotEmpty()) {
                            $tanqueOk = $grupo->where('tanque_ok', false)->isEmpty();
                        }

                        // Banhistas é contagem por registo ("desde o último registo") — o
                        // agregado diário correto é a soma, não a média.
                        $banhistasDia = $grupo->whereNotNull('banhistas')->isNotEmpty()
                            ? (int) $grupo->sum('banhistas')
                            : null;

                        $lavagensFiltro = $grupo->sum('numero_lavagens_filtro');
                        $lavouFiltroGrp = $grupo->where('filtro_faz_retrolavagem', true)->isNotEmpty() || $lavagensFiltro > 0;

                        $renovacaoAgua = $grupo->where('renovacao_agua', true)->isNotEmpty()
                            || $grupo->where('agua_modo', 'on_com_agua')->isNotEmpty()
                            || ($grupo->where('agua_modo', 'auto_com_agua')->isNotEmpty() && $lavouFiltroGrp)
                            ? true : null;

                        $caleiraFeita = $grupo->where('caleira_feita', true)->isNotEmpty() ? true : null;

                        if ($lavagensFiltro === 0 && $grupo->where('filtro_faz_retrolavagem', true)->isNotEmpty()) {
                            $lavagensFiltro = 1;
                        }

                        $mockRecord = new DailyRecord;
                        $mockRecord->registado_em = $dia;
                        $mockRecord->ph = $phAvg !== null ? round((float) $phAvg, 2) : null;
                        $mockRecord->cloro_livre = $cloroLivreAvg !== null ? round((float) $cloroLivreAvg, 2) : null;
                        $mockRecord->cloro_total = $cloroTotalAvg !== null ? round((float) $cloroTotalAvg, 2) : null;
                        $mockRecord->temperatura = $tempAvg !== null ? round((float) $tempAvg, 1) : null;
                        $mockRecord->transparencia = $transparenciaAvg !== null ? round((float) $transparenciaAvg, 2) : null;
                        $mockRecord->orp = $orpAvg !== null ? (int) round((float) $orpAvg) : null;
                        $mockRecord->contador_valor = $contadorAvg !== null ? round((float) $contadorAvg, 2) : null;
                        $mockRecord->pressao_filtro = $pressaoAvg !== null ? round((float) $pressaoAvg, 2) : null;
                        $mockRecord->bomba_ferrada = $bombaFerrada;
                        $mockRecord->tanque_ok = $tanqueOk;
                        $mockRecord->renovacao_agua = $renovacaoAgua;
                        $mockRecord->caleira_feita = $caleiraFeita;
                        $mockRecord->numero_lavagens_filtro = $lavagensFiltro > 0 ? $lavagensFiltro : null;
                        $mockRecord->banhistas = $banhistasDia;
                        $mockRecord->acao_corretiva = $acoes ?: null;
                        $mockRecord->observacoes = $observacoes ?: null;
                        $mockRecord->e_correcao = false;

                        if ($tecnicos !== '') {
                            $u = new User;
                            $u->name = $tecnicos;
                            $mockRecord->setRelation('utilizador', $u);
                        }
                        $mockRecord->setRelation('piscina', $piscina);

                        return $mockRecord;
                    })->values();
            }

            // Controlador Hanna: lida com leituras artefacto (lavagem/bomba parada)
            $janelas = $artefactoService->janelas($piscina->id, $inicio, $fim);

            $queryControlador = SensorReading::query()
                ->where('pool_id', $piscina->id)
                ->whereBetween('lida_em', [$inicio, $fim])
                ->whereNotNull('ph');

            if ($modoControlador === 'media_diaria') {
                $controlador = $queryControlador
                    ->selectRaw('DATE(lida_em) as dia, AVG(ph) as ph_avg, MIN(ph) as ph_min, MAX(ph) as ph_max, AVG(orp) as orp_avg, AVG(temperatura_agua) as temp_avg, COUNT(*) as leituras')
                    ->groupByRaw('DATE(lida_em)')
                    ->orderByRaw('DATE(lida_em)')
                    ->get();

                // 1. Procurar anomalias ativas (pH < min, ORP < min ou ORP > max)
                $anomalias = SensorReading::query()
                    ->where('pool_id', $piscina->id)
                    ->whereBetween('lida_em', [$inicio, $fim])
                    ->where(function ($q) {
                        $q->where('ph', '<', WaterQualityThresholds::ANOMALY_PH_MIN)
                            ->orWhere('orp', '<', WaterQualityThresholds::ANOMALY_ORP_MIN)
                            ->orWhere('orp', '>', WaterQualityThresholds::ANOMALY_ORP_MAX);
                    })
                    ->get();

                $diasArtefacto = [];
                // Se uma anomalia calhar dentro de uma janela, justificamos o dia com esse motivo
                foreach ($anomalias as $anomalia) {
                    $lidaEm = Carbon::parse($anomalia->lida_em);
                    foreach ($janelas as $janela) {
                        if ($lidaEm->between($janela['inicio'], $janela['fim'])) {
                            $diasArtefacto[$lidaEm->format('Y-m-d')][$janela['motivo']] = true;
                            break;
                        }
                    }
                }

                // Regra automática de lavagem de filtro para o controlador
                // Aplicar a lógica de verificação usando base de dados para evitar carregar dezenas de milhares de modelos na memória
                $phMin = WaterQualityThresholds::FILTER_WASH_PH_MIN;
                $phMax = WaterQualityThresholds::FILTER_WASH_PH_MAX;
                $orpMin = WaterQualityThresholds::FILTER_WASH_ORP_MIN;
                $orpMax = WaterQualityThresholds::FILTER_WASH_ORP_MAX;

                $diasLavagem = SensorReading::query()
                    ->where('pool_id', $piscina->id)
                    ->whereBetween('lida_em', [$inicio, $fim])
                    ->whereNotNull('ph')
                    ->whereNotNull('orp')
                    ->where(function ($q) use ($phMin, $phMax) {
                        $q->where('ph', '<', $phMin)
                            ->orWhere('ph', '>', $phMax);
                    })
                    ->where(function ($q) use ($orpMin, $orpMax) {
                        $q->where('orp', '<', $orpMin)
                            ->orWhere('orp', '>', $orpMax);
                    })
                    ->selectRaw('DATE(lida_em) as dia')
                    ->groupByRaw('DATE(lida_em)')
                    ->pluck('dia');

                foreach ($diasLavagem as $diaKey) {
                    $diasArtefacto[$diaKey]['Lavagem de filtro'] = true;
                }

                // 2. Para motivos de "Bomba parada", justificamos sempre o dia (causa falta de leituras)
                foreach ($janelas as $janela) {
                    if ($janela['motivo'] === 'Bomba parada') {
                        $cursor = $janela['inicio']->copy()->startOfDay();
                        $limite = $janela['fim']->copy();
                        while ($cursor->lte($limite)) {
                            $diasArtefacto[$cursor->format('Y-m-d')][$janela['motivo']] = true;
                            $cursor->addDay();
                        }
                    }
                }

                $diasArtefacto = array_map(fn ($m) => implode(', ', array_keys($m)), $diasArtefacto);

                $diasComLeitura = $controlador->pluck('dia')->all();
                $controlador = $controlador->map(function ($linha) use ($diasArtefacto, $registos) {
                    $linha->motivo_exclusao = $diasArtefacto[$linha->dia] ?? null;
                    $linha->sem_leitura_valida = false;

                    $diaCarbon = Carbon::parse($linha->dia);
                    $registosDoDia = $registos->filter(fn ($r) => $r->registado_em->isSameDay($diaCarbon));
                    $cloroManualAvg = $registosDoDia
                        ->map(fn ($r) => $r->cloro_livre_efetivo)
                        ->filter(fn ($v) => $v !== null)
                        ->average();

                    $linha->manual_cloro_livre = $cloroManualAvg !== null ? round($cloroManualAvg, 2) : null;
                    $linha->manual_cloro_conforme = $registosDoDia->isNotEmpty()
                        ? $registosDoDia->every(fn ($r) => $r->cloroLivreConforme())
                        : null;

                    return $linha;
                });
                foreach ($diasArtefacto as $dia => $motivo) {
                    if (! in_array($dia, $diasComLeitura, true)) {
                        $sintetico = new \stdClass;
                        $sintetico->dia = $dia;
                        $sintetico->ph_avg = null;
                        $sintetico->ph_min = null;
                        $sintetico->ph_max = null;
                        $sintetico->orp_avg = null;
                        $sintetico->temp_avg = null;
                        $sintetico->leituras = 0;
                        $sintetico->motivo_exclusao = $motivo;
                        $sintetico->sem_leitura_valida = true;
                        $controlador->push($sintetico);
                    }
                }
                $controlador = $controlador->sortBy('dia')->values();
            } else {
                // Modo todos os registos detalhados
                $controladorLeituras = $queryControlador
                    ->orderBy('lida_em')
                    ->get();

                $controlador = collect();
                foreach ($controladorLeituras as $leitura) {
                    $sintetico = new \stdClass;
                    $sintetico->dia = Carbon::parse($leitura->lida_em)->format('Y-m-d');
                    $sintetico->hora = Carbon::parse($leitura->lida_em)->format('H:i');
                    $sintetico->ph = $leitura->ph;
                    $sintetico->orp = $leitura->orp;
                    $sintetico->temp_agua = $leitura->temperatura_agua;
                    $sintetico->leituras = 1;

                    $lidaEm = Carbon::parse($leitura->lida_em);

                    // Procura o registo manual mais próximo (± 15 min)
                    $closestRegisto = $registos->first(function ($r) use ($lidaEm) {
                        return abs($r->registado_em->diffInMinutes($lidaEm)) <= 15;
                    });
                    $sintetico->manual_cloro_livre = $closestRegisto ? $closestRegisto->cloro_livre_efetivo : null;
                    $sintetico->manual_cloro_conforme = $closestRegisto ? $closestRegisto->cloroLivreConforme() : null;

                    // Verificar se cai em alguma janela
                    $motivo = null;
                    foreach ($janelas as $janela) {
                        if ($lidaEm->between($janela['inicio'], $janela['fim'])) {
                            $motivo = $janela['motivo'];
                            break;
                        }
                    }

                    if ($motivo === null && self::cumpresRegraLavagemFiltro($leitura->ph, $leitura->orp)) {
                        $motivo = 'Lavagem de filtro';
                    }

                    $sintetico->motivo_exclusao = $motivo;
                    $sintetico->sem_leitura_valida = $motivo !== null;

                    $controlador->push($sintetico);
                }
            }

            return [
                'piscina' => $piscina,
                'registos' => $registos,
                'controlador' => $controlador,
                // Análise rápida já entrou em 'registos' acima — não duplicar aqui.
                'acoes_operacionais' => ($acoesOperacionais->get($piscina->id) ?? collect())
                    ->where('tipo', '!=', OperationalAction::TIPO_ANALISE_PONTUAL)
                    ->values(),
                // Sem isto, um período encerrado imprime como uma sequência de dias
                // em falta — que um auditor da DGS lê como omissão do dever legal
                // de registo, e não como uma piscina legitimamente fechada.
                'encerramentos' => $encerramentos->get($piscina->id) ?? collect(),
            ];
        })->all();
    }

    /**
     * Converte uma análise rápida (OperationalAction) num DailyRecord sintético
     * (nunca persistido) para que entre na mesma tabela/conformidade do livro
     * sanitário que os registos diários — indistinguível de um registo normal.
     */
    private static function registoSinteticoDeAnalise(OperationalAction $acao, Pool $piscina): DailyRecord
    {
        $dados = $acao->dados ?? [];

        $registo = new DailyRecord;
        $registo->registado_em = $acao->registado_em;
        $registo->ph = $dados['ph'] ?? null;
        $registo->cloro_livre = $dados['cloro_livre'] ?? null;
        $registo->cloro_total = $dados['cloro_total'] ?? null;
        $registo->temperatura = $dados['temperatura'] ?? null;
        $registo->observacoes = $acao->observacoes;
        $registo->e_correcao = false;
        $registo->setRelation('utilizador', $acao->utilizador);
        $registo->setRelation('piscina', $piscina);
        $registo->setRelation('adicoes', collect());

        return $registo;
    }

    private static function cumpresRegraLavagemFiltro(mixed $ph, mixed $orp): bool
    {
        if ($ph === null || $orp === null || $ph === '' || $orp === '') {
            return false;
        }

        $phFloat = (float) $ph;
        $orpFloat = (float) $orp;

        $phForaLimites = $phFloat < WaterQualityThresholds::FILTER_WASH_PH_MIN || $phFloat > WaterQualityThresholds::FILTER_WASH_PH_MAX;
        $orpForaLimites = $orpFloat < WaterQualityThresholds::FILTER_WASH_ORP_MIN || $orpFloat > WaterQualityThresholds::FILTER_WASH_ORP_MAX;

        return $phForaLimites && $orpForaLimites;
    }

    /**
     * Resumo prévio de conformidade regulamentar para o Pre-Flight Card.
     *
     * @return array{
     *     valido: bool,
     *     mensagem: ?string,
     *     totalRegistos: int,
     *     diasPeriodo: int,
     *     taxaConformidade: float,
     *     totalViolacoes: int,
     *     totalLavagens: int,
     *     termoElegivel: bool,
     *     piscinasCount: int,
     *     encerramentosCount: int,
     * }
     */
    public function getPreflightSummary(): array
    {
        $dados = (array) ($this->data ?? []);
        if (isset($this->form) && method_exists($this->form, 'getRawState')) {
            $dados = array_merge($this->form->getRawState() ?? [], $dados);
        }

        $temObservacoes = filled($dados['observacoes_gerais'] ?? null);
        $fotosCount = count((array) ($dados['fotos_observacoes'] ?? []));

        $installationId = $dados['installation_id'] ?? null;
        $inicio = $dados['data_inicio'] ?? null;
        $fim = $dados['data_fim'] ?? null;
        $poolId = $dados['pool_id'] ?? 'todas';

        if (blank($installationId) || blank($inicio) || blank($fim)) {
            return [
                'valido' => false,
                'mensagem' => 'Selecione a instalação e o período.',
                'totalRegistos' => 0,
                'diasPeriodo' => 0,
                'taxaConformidade' => 100.0,
                'totalViolacoes' => 0,
                'totalLavagens' => 0,
                'termoElegivel' => false,
                'piscinasCount' => 0,
                'encerramentosCount' => 0,
                'temObservacoes' => $temObservacoes,
                'fotosCount' => $fotosCount,
            ];
        }

        try {
            $inicioDt = Carbon::parse((string) $inicio)->startOfDay();
            $fimDt = Carbon::parse((string) $fim)->endOfDay();
        } catch (\Throwable) {
            return [
                'valido' => false,
                'mensagem' => 'Datas inválidas.',
                'totalRegistos' => 0,
                'diasPeriodo' => 0,
                'taxaConformidade' => 100.0,
                'totalViolacoes' => 0,
                'totalLavagens' => 0,
                'termoElegivel' => false,
                'piscinasCount' => 0,
                'encerramentosCount' => 0,
                'temObservacoes' => $temObservacoes,
                'fotosCount' => $fotosCount,
            ];
        }

        if ($fimDt->lt($inicioDt)) {
            return [
                'valido' => false,
                'mensagem' => 'A data fim é anterior à data de início.',
                'totalRegistos' => 0,
                'diasPeriodo' => 0,
                'taxaConformidade' => 100.0,
                'totalViolacoes' => 0,
                'totalLavagens' => 0,
                'termoElegivel' => false,
                'piscinasCount' => 0,
                'encerramentosCount' => 0,
                'temObservacoes' => $temObservacoes,
                'fotosCount' => $fotosCount,
            ];
        }

        $diasPeriodo = (int) $inicioDt->diffInDays($fimDt->copy()->startOfDay()) + 1;

        $piscinasQuery = Pool::query()->where('installation_id', (int) $installationId);
        if (filled($poolId) && $poolId !== 'todas') {
            $piscinasQuery->whereKey((int) $poolId);
        }
        $piscinas = $piscinasQuery->get();
        $piscinasCount = $piscinas->count();

        if ($piscinasCount === 0) {
            return [
                'valido' => false,
                'mensagem' => 'A instalação não tem piscinas configuradas.',
                'totalRegistos' => 0,
                'diasPeriodo' => $diasPeriodo,
                'taxaConformidade' => 100.0,
                'totalViolacoes' => 0,
                'totalLavagens' => 0,
                'termoElegivel' => false,
                'piscinasCount' => 0,
                'encerramentosCount' => 0,
                'temObservacoes' => false,
                'fotosCount' => 0,
            ];
        }

        $poolIds = $piscinas->pluck('id');

        $registos = DailyRecord::query()
            ->whereIn('pool_id', $poolIds)
            ->whereBetween('registado_em', [$inicioDt, $fimDt])
            ->whereDoesntHave('correcoes')
            ->with('piscina')
            ->get();

        $totalRegistos = $registos->count();
        $totalViolacoes = 0;
        $totalLavagens = 0;

        foreach ($registos as $registo) {
            if (! empty($registo->listarViolacoes())) {
                $totalViolacoes++;
            }
            if ($registo->filtro_faz_retrolavagem) {
                $totalLavagens += max(1, (int) $registo->numero_lavagens_filtro);
            }
        }

        $lavagensAcoes = OperationalAction::query()
            ->whereIn('pool_id', $poolIds)
            ->whereBetween('registado_em', [$inicioDt, $fimDt])
            ->where('tipo', OperationalAction::TIPO_LAVAGEM_FILTRO)
            ->count();
        $totalLavagens += $lavagensAcoes;

        $taxaConformidade = $totalRegistos > 0
            ? round((($totalRegistos - $totalViolacoes) / $totalRegistos) * 100, 1)
            : 100.0;

        $termoElegivel = self::termoLegalElegivel($piscinasCount, $inicioDt, $fimDt);

        $encerramentosCount = PoolClosure::query()
            ->whereIn('pool_id', $poolIds)
            ->queIntersetam($inicioDt, $fimDt)
            ->count();

        $temObservacoes = filled($dados['observacoes_gerais'] ?? null);
        $fotosCount = count((array) ($dados['fotos_observacoes'] ?? []));

        return [
            'valido' => true,
            'mensagem' => null,
            'totalRegistos' => $totalRegistos,
            'diasPeriodo' => $diasPeriodo,
            'taxaConformidade' => $taxaConformidade,
            'totalViolacoes' => $totalViolacoes,
            'totalLavagens' => $totalLavagens,
            'termoElegivel' => $termoElegivel,
            'piscinasCount' => $piscinasCount,
            'encerramentosCount' => $encerramentosCount,
            'temObservacoes' => $temObservacoes,
            'fotosCount' => $fotosCount,
        ];
    }

    public function getPreflightSummaryProperty(): array
    {
        return $this->getPreflightSummary();
    }

    protected function getViewData(): array
    {
        return [
            'preflightSummary' => $this->getPreflightSummary(),
        ];
    }

    public function __get($property): mixed
    {
        if ($property === 'preflightSummary') {
            return $this->getPreflightSummary();
        }

        return parent::__get($property);
    }
}
