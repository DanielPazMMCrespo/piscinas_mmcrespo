<?php declare(strict_types=1);
namespace App\Filament\Pages;


use App\Models\Installation;
use App\Models\Pool;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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

    protected static ?string $navigationGroup = 'Operação';

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
        // Defaults: primeiro dia do mês corrente até hoje.
        $this->form->fill([
            'installation_id' => null,
            'pool_id' => 'todas',
            'data_inicio' => now()->startOfMonth()->toDateString(),
            'data_fim' => now()->toDateString(),
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
                            ->maxDate(now())
                            ->afterOrEqual('data_inicio')
                            ->displayFormat('d/m/Y')
                            ->native(false)
                            ->validationMessages([
                                'after_or_equal' => 'A data fim tem de ser igual ou posterior à data início.',
                            ]),
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

        // Uma secção por piscina: registos do período, sem registos já corrigidos
        // (append-only: a versão válida é a correção; ver regra 4 do CLAUDE.md).
        $seccoes = $piscinas->map(function (Pool $piscina) use ($inicio, $fim): array {
            $registos = $piscina->registosDiarios()
                ->with(['utilizador', 'piscina'])
                ->whereBetween('registado_em', [$inicio, $fim])
                ->whereDoesntHave('correcoes')
                ->orderBy('registado_em')
                ->get();

            return [
                'piscina' => $piscina,
                'registos' => $registos,
            ];
        })->all();

        $pdf = Pdf::loadView('pdf.livro-sanitario', [
            'instalacao' => $instalacao,
            'seccoes' => $seccoes,
            'inicio' => $inicio,
            'fim' => $fim,
            'emitidoEm' => now(),
            'emitidoPor' => auth()->user()?->name,
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
