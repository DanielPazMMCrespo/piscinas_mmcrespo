<?php declare(strict_types=1);
namespace App\Filament\Pages;


use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
class RelatorioPdf extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Dados';

    protected static ?string $navigationLabel = 'Relatório PDF (CN 14/DA)';

    protected static ?string $title = 'Relatório PDF — Livro de Registo Sanitário (CN 14/DA)';

    protected static ?int $navigationSort = 6;

    protected static string $view = 'filament.pages.relatorio-pdf';

    /**
     * Estado do formulário (statePath).
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /** Apenas admin e técnico podem emitir relatórios regulamentares. */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['admin', 'tecnico']);
    }

    public function mount(): void
    {
        // Defaults: primeiro dia do mês corrente até ontem.
        $this->form->fill([
            'installation_id' => null,
            'pool_id' => 'todas',
            'data_inicio' => now()->startOfMonth()->toDateString(),
            'data_fim' => now()->subDay()->toDateString(),
            'registo_modo' => 'todos',
            'colunas_visiveis' => [
                'hora', 'tecnico', 'ph', 'cloro_livre', 'cloro_total',
                'cloro_combinado', 'temperatura', 'transparencia',
                'contador_valor', 'bomba_tanque', 'acao_corretiva',
                'observacoes', 'conforme',
            ],
            'seccoes_visiveis' => [
                'mostrar_resumo', 'mostrar_controlador_grafico',
                'mostrar_controlador_tabela', 'mostrar_assinaturas',
                'mostrar_nota_legal',
            ],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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
                            ->required(),

                        DatePicker::make('data_inicio')
                            ->label('Data início')
                            ->required()
                            ->maxDate(now())
                            ->displayFormat('d/m/Y')
                            ->native(false),

                        DatePicker::make('data_fim')
                            ->label('Data fim')
                            ->required()
                            ->maxDate(now()->subDay())
                            ->afterOrEqual('data_inicio')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->validationMessages([
                                'after_or_equal' => 'A data fim tem de ser igual ou posterior à data início.',
                            ]),
                    ]),

                Section::make('Opções de personalização do PDF')
                    ->description('Personalize as colunas e secções que vão constar no documento PDF.')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsible()
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Placeholder::make('aviso_customizacao')
                            ->hidden(fn (Get $get) => 
                                $get('registo_modo') === 'todos' &&
                                count($get('colunas_visiveis') ?? []) === 13 &&
                                count($get('seccoes_visiveis') ?? []) === 5
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
                            ->label('Tipo de agrupamento')
                            ->options([
                                'todos' => 'Todos os registos diários',
                                'media_diaria' => 'Média diária (um registo por dia)',
                            ])
                            ->required()
                            ->live(),

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
                                'contador_valor' => 'Contador',
                                'bomba_tanque' => 'Bomba / Tanque',
                                'acao_corretiva' => 'Ações corretivas',
                                'observacoes' => 'Observações',
                                'conforme' => 'Conformidade',
                            ])
                            ->columns(2)
                            ->live(),

                        CheckboxList::make('seccoes_visiveis')
                            ->label('Outros elementos do PDF')
                            ->options([
                                'mostrar_resumo' => 'Resumo da conformidade da piscina',
                                'mostrar_controlador_grafico' => 'Gráfico do controlador Hanna BL132',
                                'mostrar_controlador_tabela' => 'Tabela do controlador Hanna BL132',
                                'mostrar_assinaturas' => 'Área de assinaturas',
                                'mostrar_nota_legal' => 'Nota legal de rodapé',
                            ])
                            ->columns(1)
                            ->live(),
                    ]),
            ])
            ->statePath('data');
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

        $modo = $estado['registo_modo'] ?? 'todos';
        $colunasVisiveis = $estado['colunas_visiveis'] ?? [];
        $seccoesVisiveis = $estado['seccoes_visiveis'] ?? [];

        // Leituras do controlador agregadas por dia (média, min, max por piscina).
        // Agrupadas por pool_id para acesso O(1) na montagem das secções.
        $leiturasControlador = SensorReading::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->whereBetween('lida_em', [$inicio, $fim])
            ->selectRaw('pool_id, DATE(lida_em) as dia, AVG(ph) as ph_avg, MIN(ph) as ph_min, MAX(ph) as ph_max, AVG(orp) as orp_avg, AVG(temperatura_agua) as temp_avg, COUNT(*) as leituras')
            ->whereNotNull('ph')
            ->groupByRaw('pool_id, DATE(lida_em)')
            ->orderByRaw('DATE(lida_em)')
            ->get()
            ->groupBy('pool_id');

        // Uma secção por piscina: registos do período, sem registos já corrigidos
        // (append-only: a versão válida é a correção; ver regra 4 do CLAUDE.md).
        $seccoes = $piscinas->map(function (Pool $piscina) use ($inicio, $fim, $leiturasControlador, $modo): array {
            $registos = $piscina->registosDiarios()
                ->with(['utilizador', 'piscina'])
                ->whereBetween('registado_em', [$inicio, $fim])
                ->whereDoesntHave('correcoes')
                ->orderBy('registado_em')
                ->get();

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
                        
                        $acoes = $grupo->pluck('acao_corretiva')->filter()->unique()->implode('; ');
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

                        $mockRecord = new \App\Models\DailyRecord();
                        $mockRecord->registado_em = $dia;
                        $mockRecord->ph = $phAvg !== null ? round((float)$phAvg, 2) : null;
                        $mockRecord->cloro_livre = $cloroLivreAvg !== null ? round((float)$cloroLivreAvg, 2) : null;
                        $mockRecord->cloro_total = $cloroTotalAvg !== null ? round((float)$cloroTotalAvg, 2) : null;
                        $mockRecord->temperatura = $tempAvg !== null ? round((float)$tempAvg, 1) : null;
                        $mockRecord->transparencia = $transparenciaAvg !== null ? round((float)$transparenciaAvg, 2) : null;
                        $mockRecord->contador_valor = $contadorAvg !== null ? round((float)$contadorAvg, 2) : null;
                        $mockRecord->bomba_ferrada = $bombaFerrada;
                        $mockRecord->tanque_ok = $tanqueOk;
                        $mockRecord->acao_corretiva = $acoes ?: null;
                        $mockRecord->observacoes = $observacoes ?: null;
                        $mockRecord->e_correcao = false;

                        if ($tecnicos !== '') {
                            $u = new \App\Models\User();
                            $u->name = $tecnicos;
                            $mockRecord->setRelation('utilizador', $u);
                        }
                        $mockRecord->setRelation('piscina', $piscina);

                        return $mockRecord;
                    })->values();
            }

            return [
                'piscina' => $piscina,
                'registos' => $registos,
                'controlador' => $leiturasControlador->get($piscina->id) ?? collect(),
            ];
        })->all();

        $pdf = Pdf::loadView('pdf.livro-sanitario', [
            'instalacao' => $instalacao,
            'seccoes' => $seccoes,
            'inicio' => $inicio,
            'fim' => $fim,
            'emitidoEm' => now(),
            'emitidoPor' => auth()->user()?->name,
            'colunasVisiveis' => $colunasVisiveis,
            'seccoesVisiveis' => $seccoesVisiveis,
            'modo' => $modo,
        ])->setPaper('a4', 'landscape');

        // Numeração "Página X de Y": render explícito no objeto Dompdf e
        // page_text no canvas ANTES de extrair o output (script PHP inline
        // do dompdf está desativado por omissão — esta é a via suportada).
        $domPdf = $pdf->getDomPDF();
        $domPdf->render();

        $canvas = $domPdf->getCanvas();
        $fonte = $domPdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(
            $canvas->get_width() - 130,
            $canvas->get_height() - 26,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $fonte,
            7.0,
            [0, 0, 0],
        );

        $conteudo = (string) $domPdf->output();

        $nomePiscina = $todas
            ? 'todas'
            : Str::slug((string) $piscinas->first()?->name);

        $nomeFicheiro = sprintf(
            'livro-sanitario_%s_%s_%s_%s.pdf',
            Str::slug($instalacao->name),
            $nomePiscina,
            $inicio->format('Y-m-d'),
            $fim->format('Y-m-d'),
        );

        return response()->streamDownload(
            fn () => print($conteudo),
            $nomeFicheiro,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
