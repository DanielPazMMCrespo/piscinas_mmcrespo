<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Filament\Pages\RelatorioPdf;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GerarLivroSanitarioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function __construct(
        public readonly User $user,
        public readonly array $estado
    ) {}

    public function handle(): void
    {
        ini_set('memory_limit', '1024M');

        $estado = $this->estado;
        $inicio = Carbon::parse((string) $estado['data_inicio'])->startOfDay();
        $fim = Carbon::parse((string) $estado['data_fim'])->endOfDay();

        $instalacao = Installation::query()->find((int) $estado['installation_id']);
        if (! $instalacao) return;

        $todas = $estado['pool_id'] === 'todas';
        $piscinas = $instalacao->piscinas()
            ->when(! $todas, fn ($query) => $query->whereKey((int) $estado['pool_id']))
            ->orderBy('name')
            ->get();

        if ($piscinas->isEmpty()) return;

        $modo = $estado['registo_modo'] ?? 'todos';
        $modoControlador = $estado['controlador_modo'] ?? 'media_diaria';
        $colunasVisiveis = $estado['colunas_visiveis'] ?? [];
        $seccoesVisiveis = $estado['seccoes_visiveis'] ?? [];

        $seccoes = RelatorioPdf::construirSeccoes($piscinas, $inicio, $fim, $modo, $modoControlador);

        $pdf = Pdf::loadView('pdf.livro-sanitario', [
            'instalacao' => $instalacao,
            'seccoes' => $seccoes,
            'inicio' => $inicio,
            'fim' => $fim,
            'emitidoEm' => now(),
            'emitidoPor' => $this->user->name,
            'colunasVisiveis' => $colunasVisiveis,
            'seccoesVisiveis' => $seccoesVisiveis,
            'modo' => $modo,
            'controladorModo' => $modoControlador,
        ])->setPaper('a4', 'landscape');

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

        $nomePiscina = $todas ? 'todas' : Str::slug((string) $piscinas->first()?->name);
        $nomeFicheiro = sprintf(
            'livro-sanitario_%s_%s_%s_%s.pdf',
            Str::slug($instalacao->name),
            $nomePiscina,
            $inicio->format('Y-m-d'),
            $fim->format('Y-m-d')
        );

        $path = "relatorios/{$nomeFicheiro}";
        Storage::disk('public')->put($path, $conteudo);

        activity('relatorio')
            ->causedBy($this->user)
            ->log("Gerou relatório PDF assíncrono: {$nomeFicheiro}");

        Notification::make()
            ->title('Livro Sanitário Concluído')
            ->body('O relatório solicitado já se encontra disponível para download.')
            ->success()
            ->actions([
                Action::make('download')
                    ->label('Descarregar PDF')
                    ->url(Storage::disk('public')->url($path), shouldOpenInNewTab: true)
                    ->button(),
            ])
            ->sendToDatabase($this->user);
    }
}
