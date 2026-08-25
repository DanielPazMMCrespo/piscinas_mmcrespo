<?php

declare(strict_types=1);

namespace App\Support;

use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ponto único de render de PDF com numeração de páginas.
 *
 * [AI_CONTEXT]
 * - A numeração tem de ser escrita no canvas depois de `render()`: o script PHP
 *   inline do dompdf está desativado por segurança, logo `{PAGE_NUM}` não pode
 *   vir da blade. Este bloco estava copiado à letra — magic numbers incluídos —
 *   em `RelatorioPdf::exportar()` e em `GerarRelatorioMensalCommand::handle()`.
 * - `render()` devolve o `Dompdf`, não bytes: um chamador faz `streamDownload`,
 *   outro escreve para o disco R2. Devolver bytes obrigava o comando mensal a
 *   segurar o ficheiro inteiro duas vezes em memória.
 * - Quem chamar isto continua a ser responsável pelo `ini_set('memory_limit')`
 *   e pelo `activity('relatorio')`: são decisões do chamador, não do render.
 */
final class PdfRenderer
{
    private const MARGEM_DIREITA_PT = 130;

    private const MARGEM_INFERIOR_PT = 26;

    private const TAMANHO_FONTE = 7.0;

    private const FONTE = 'DejaVu Sans';

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $dados
     */
    public static function render(string $view, array $dados, string $orientacao = 'landscape'): Dompdf
    {
        $domPdf = Pdf::loadView($view, $dados)->setPaper('a4', $orientacao)->getDomPDF();
        $domPdf->render();

        $canvas = $domPdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - self::MARGEM_DIREITA_PT,
            $canvas->get_height() - self::MARGEM_INFERIOR_PT,
            'Página {PAGE_NUM} de {PAGE_COUNT}',
            $domPdf->getFontMetrics()->getFont(self::FONTE),
            self::TAMANHO_FONTE,
            [0, 0, 0],
        );

        return $domPdf;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public static function stream(string $view, array $dados, string $nomeFicheiro, string $orientacao = 'landscape'): StreamedResponse
    {
        $domPdf = self::render($view, $dados, $orientacao);

        return response()->streamDownload(
            fn () => print ($domPdf->output()),
            $nomeFicheiro,
            ['Content-Type' => 'application/pdf']
        );
    }
}
