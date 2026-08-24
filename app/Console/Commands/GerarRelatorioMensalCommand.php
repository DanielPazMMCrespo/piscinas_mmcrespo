<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\UserRole;
use App\Filament\Pages\RelatorioPdf;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\User;
use App\Notifications\RelatorioMensalDisponivelNotification;
use App\Support\PdfRenderer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gera automaticamente, no dia 1 de cada mês, o livro sanitário PDF do mês
 * anterior para cada instalação, e notifica admin/gestor com o link.
 * Reutiliza RelatorioPdf::construirSeccoes() — o mesmo motor do relatório
 * manual — para não duplicar a lógica regulamentar do CN 14/DA.
 */
class GerarRelatorioMensalCommand extends Command
{
    protected $signature = 'relatorio:mensal-automatico';

    protected $description = 'Gera o livro sanitário PDF do mês anterior por instalação e notifica admin/gestor';

    private const COLUNAS_VISIVEIS = [
        'hora', 'tecnico', 'ph', 'cloro_livre', 'cloro_total',
        'cloro_combinado', 'temperatura', 'transparencia',
        'contador_valor', 'bomba_tanque', 'acao_corretiva', 'observacoes', 'conforme',
    ];

    private const SECCOES_VISIVEIS = [
        'mostrar_resumo', 'mostrar_controlador_grafico',
        'mostrar_controlador_tabela', 'mostrar_acoes_operacionais',
        'mostrar_assinaturas', 'mostrar_nota_legal',
    ];

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(240);

        $inicio = now()->subMonthNoOverflow()->startOfMonth();
        $fim = now()->subMonthNoOverflow()->endOfMonth();
        $mesLabel = $inicio->locale('pt')->isoFormat('MMMM YYYY');

        $destinatarios = User::role([UserRole::ADMIN, UserRole::GESTOR])->get();

        foreach (Installation::query()->orderBy('name')->get() as $instalacao) {
            $piscinas = $instalacao->piscinas()->orderBy('name')->get();
            if ($piscinas->isEmpty()) {
                continue;
            }

            $seccoes = RelatorioPdf::construirSeccoes($piscinas, $inicio, $fim, 'todos', 'media_diaria');

            $domPdf = PdfRenderer::render('pdf.livro-sanitario', [
                'instalacao' => $instalacao,
                'seccoes' => $seccoes,
                'inicio' => $inicio,
                'fim' => $fim,
                'emitidoEm' => now(),
                'emitidoPor' => 'Sistema (relatório mensal automático)',
                'colunasVisiveis' => self::COLUNAS_VISIVEIS,
                'seccoesVisiveis' => self::SECCOES_VISIVEIS,
                'modo' => 'todos',
                'controladorModo' => 'media_diaria',
            ]);

            $nomeFicheiro = sprintf('livro-sanitario_%s_%s.pdf', Str::slug($instalacao->name), $inicio->format('Y-m'));
            $caminho = 'relatorios-mensais/'.$nomeFicheiro;

            Storage::disk(DailyRecord::getStorageDisk())->put($caminho, (string) $domPdf->output());

            $url = DailyRecord::getStorageUrl($caminho);

            activity('relatorio')
                ->log("Gerou relatório mensal automático: {$nomeFicheiro}");

            if ($destinatarios->isNotEmpty()) {
                Notification::send(
                    $destinatarios,
                    new RelatorioMensalDisponivelNotification($instalacao->name, $mesLabel, $url),
                );
            }
        }

        return self::SUCCESS;
    }
}
