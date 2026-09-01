<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\TrabalhoParagem;
use App\Models\DailyRecord;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\User;
use App\Support\PdfRenderer;
use Dompdf\Dompdf;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serviço de geração de PDFs regulamentares para Paragem Técnica:
 * - Plano de Trabalhos (documento prévio/programático)
 * - Relatório de Paragem e Manutenção (documento executivo final)
 */
class PlanoParagemPdfService
{
    private const MAX_FOTOS_EMBED = 30;

    private const MAX_FOTO_BYTES = 2097152; // 2 MB

    public function __construct(
        private readonly PlanoParagemService $planoService,
        private readonly ?EvidenciaParagemService $evidenciaService = null
    ) {}

    /**
     * Gera o Dompdf do Plano de Trabalhos (A4 portrait).
     */
    public function gerarPlanoPdf(PoolClosure $encerramento, ?User $emitente = null): Dompdf
    {
        $dados = $this->prepararDadosPlano($encerramento, $emitente);

        $this->registarAuditoria($encerramento, 'plano_paragem_pdf', 'Plano de trabalhos em PDF gerado.', $emitente);

        return PdfRenderer::render('pdf.paragem.plano', $dados, 'portrait');
    }

    /**
     * Devolve StreamedResponse do Plano de Trabalhos.
     */
    public function streamPlano(PoolClosure $encerramento, ?User $emitente = null): StreamedResponse
    {
        $dados = $this->prepararDadosPlano($encerramento, $emitente);
        $nomeFicheiro = $this->gerarNomeFicheiro($encerramento, 'plano_trabalhos');

        $this->registarAuditoria($encerramento, 'plano_paragem_download', 'Download do plano de trabalhos em PDF.', $emitente);

        return PdfRenderer::stream('pdf.paragem.plano', $dados, $nomeFicheiro, 'portrait');
    }

    /**
     * Gera o Dompdf do Relatório de Paragem Técnica (A4 portrait).
     */
    public function gerarRelatorioPdf(PoolClosure $encerramento, ?User $emitente = null): Dompdf
    {
        $dados = $this->prepararDadosRelatorio($encerramento, $emitente);

        $this->registarAuditoria($encerramento, 'relatorio_paragem_pdf', 'Relatório de paragem técnica em PDF gerado.', $emitente);

        return PdfRenderer::render('pdf.paragem.relatorio', $dados, 'portrait');
    }

    /**
     * Devolve StreamedResponse do Relatório de Paragem Técnica.
     */
    public function streamRelatorio(PoolClosure $encerramento, ?User $emitente = null): StreamedResponse
    {
        $dados = $this->prepararDadosRelatorio($encerramento, $emitente);
        $nomeFicheiro = $this->gerarNomeFicheiro($encerramento, 'relatorio_paragem');

        $this->registarAuditoria($encerramento, 'relatorio_paragem_download', 'Download do relatório de paragem técnica em PDF.', $emitente);

        return PdfRenderer::stream('pdf.paragem.relatorio', $dados, $nomeFicheiro, 'portrait');
    }

    /**
     * @return array<string, mixed>
     */
    public function prepararDadosPlano(PoolClosure $encerramento, ?User $emitente = null): array
    {
        /** @var Collection<int, PoolClosureTask> $trabalhos */
        $trabalhos = $encerramento->trabalhos()->with(['executadoPor'])->orderBy('ordem')->get();
        $resumo = $this->planoService->resumo($encerramento);

        $obrigatoriosTotal = $trabalhos->where('obrigatorio', true)->count();
        $resumo['obrigatorios_total'] = $obrigatoriosTotal;

        return [
            'encerramento' => $encerramento,
            'trabalhos' => $trabalhos,
            'resumo' => $resumo,
            'emitidoEm' => Carbon::now(),
            'emitidoPor' => $emitente !== null ? $emitente->name : auth()->user()?->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function prepararDadosRelatorio(PoolClosure $encerramento, ?User $emitente = null): array
    {
        /** @var Collection<int, PoolClosureTask> $trabalhos */
        $trabalhos = $encerramento->trabalhos()->with(['executadoPor'])->orderBy('ordem')->get();

        $resumo = $this->planoService->resumo($encerramento);
        $resumo['previstos'] = $trabalhos->where('estado', TrabalhoParagem::ESTADO_PREVISTO)->count();
        $resumo['nao_executados'] = $trabalhos->where('estado', TrabalhoParagem::ESTADO_NAO_EXECUTADO)->count();
        $resumo['nao_aplicaveis'] = $trabalhos->where('estado', TrabalhoParagem::ESTADO_NAO_APLICAVEL)->count();

        // Anexo A: Ações Operacionais registadas
        $acoesOperacionais = $this->planoService->evidenciaOperacional($encerramento);

        // Processamento de Fotos e Documentos
        $fotos = $this->processarFotos($trabalhos);
        $videosIndex = $this->processarVideos($trabalhos);
        $documentosIndex = $this->processarDocumentos($trabalhos);

        // Dados de Sonda e Gráfico SVG
        $dadosSonda = $this->obterDadosSonda($encerramento);
        $graficoSvg = $dadosSonda['total_leituras'] > 0 ? $this->gerarGraficoSvg($dadosSonda, $encerramento) : null;

        // Eventos e candidatos forenses detetados
        $eventosSonda = [];
        if ($this->evidenciaService !== null) {
            $candidatos = $this->evidenciaService->candidatos($encerramento);
            foreach ($candidatos as $tipo => $lista) {
                foreach ($lista as $cand) {
                    $eventosSonda[] = array_merge($cand, [
                        'tipo' => $tipo,
                        'tipo_label' => TrabalhoParagem::label($tipo),
                    ]);
                }
            }
        }

        return [
            'encerramento' => $encerramento,
            'trabalhos' => $trabalhos,
            'resumo' => $resumo,
            'acoesOperacionais' => $acoesOperacionais,
            'fotosEmbed' => $fotos['embutidas'],
            'fotosNaoEmbutidas' => $fotos['nao_embutidas'],
            'videosIndex' => $videosIndex,
            'documentosIndex' => $documentosIndex,
            'dadosSonda' => $dadosSonda,
            'graficoSvg' => $graficoSvg,
            'eventosSonda' => $eventosSonda,
            'emitidoEm' => Carbon::now(),
            'emitidoPor' => $emitente !== null ? $emitente->name : auth()->user()?->name,
        ];
    }

    /**
     * Fotos das tarefas prontas para o PDF. As que o dompdf não consegue
     * imprimir saem em `nao_embutidas` para serem referenciadas — nunca
     * omitidas em silêncio de um documento legal.
     *
     * @param  Collection<int, PoolClosureTask>  $trabalhos
     * @return array{embutidas: array<int, array{tarefa_label: string, data: ?string, base64: string}>, nao_embutidas: array<int, array{tarefa_label: string, nome_ficheiro: string, motivo: string}>}
     */
    private function processarFotos($trabalhos): array
    {
        $disk = Storage::disk(DailyRecord::getStorageDisk());
        $fotos = [];
        $naoEmbutidas = [];

        foreach ($trabalhos as $t) {
            if (! is_array($t->fotos) || empty($t->fotos)) {
                continue;
            }

            foreach ($t->fotos as $path) {
                if (count($fotos) >= self::MAX_FOTOS_EMBED) {
                    break 2;
                }

                if (! is_string($path)) {
                    continue;
                }

                if (! $disk->exists($path)) {
                    $naoEmbutidas[] = [
                        'tarefa_label' => $t->tipoLabel(),
                        'nome_ficheiro' => basename($path),
                        'motivo' => 'ficheiro não encontrado no armazenamento',
                    ];

                    continue;
                }

                // Acima do limite o base64 (+33%) mais o bitmap descodificado
                // rebentam a memória do dompdf. Omitir em silêncio um anexo de
                // um documento legal é pior — fica referenciado.
                if ($disk->size($path) > self::MAX_FOTO_BYTES) {
                    $naoEmbutidas[] = [
                        'tarefa_label' => $t->tipoLabel(),
                        'nome_ficheiro' => basename($path),
                        'motivo' => 'excede o limite de impressão de '.(int) (self::MAX_FOTO_BYTES / 1048576).' MB',
                    ];

                    continue;
                }

                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mimeMap = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'webp' => 'image/webp',
                    'gif' => 'image/gif',
                ];

                $mime = $mimeMap[$ext] ?? null;
                if ($mime === null) {
                    try {
                        $detectedMime = $disk->mimeType($path);
                        if (is_string($detectedMime) && in_array(strtolower($detectedMime), array_values($mimeMap), true)) {
                            $mime = strtolower($detectedMime);
                        }
                    } catch (\Throwable) {
                        $mime = null;
                    }
                }

                // O dompdf não descodifica HEIC (aceite no upload) nem formatos
                // exóticos. Declarar esse conteúdo como JPEG imprimia uma caixa
                // partida num documento legal; referenciar é honesto.
                if ($mime === null) {
                    $naoEmbutidas[] = [
                        'tarefa_label' => $t->tipoLabel(),
                        'nome_ficheiro' => basename($path),
                        'motivo' => 'formato não suportado na impressão ('.($ext !== '' ? $ext : 'desconhecido').')',
                    ];

                    continue;
                }

                $content = $disk->get($path);
                if ($content === null || $content === '') {
                    continue;
                }

                $base64 = 'data:'.$mime.';base64,'.base64_encode($content);

                $fotos[] = [
                    'tarefa_label' => $t->tipoLabel(),
                    'data' => $t->executado_em?->format('d/m/Y H:i'),
                    'base64' => $base64,
                ];
            }
        }

        return ['embutidas' => $fotos, 'nao_embutidas' => $naoEmbutidas];
    }

    /**
     * Videos das tarefas. O dompdf nao reproduz video, por isso o relatorio
     * legal referencia-os pelo nome e pelo SHA-256 do conteudo — a prova de
     * integridade do ficheiro arquivado, tal como se faz nos boletins.
     *
     * @param  Collection<int, PoolClosureTask>  $trabalhos
     * @return array<int, array{tarefa_label: string, data: ?string, nome_ficheiro: string, tamanho_mb: string, sha256: string}>
     */
    private function processarVideos($trabalhos): array
    {
        $disk = Storage::disk(DailyRecord::getStorageDisk());
        $videos = [];

        foreach ($trabalhos as $t) {
            if (! is_array($t->videos) || empty($t->videos)) {
                continue;
            }

            foreach ($t->videos as $path) {
                if (! is_string($path) || $path === '') {
                    continue;
                }

                $existe = $disk->exists($path);
                $sha256 = '—';
                $tamanhoMb = '—';

                if ($existe) {
                    $conteudo = $disk->get($path);
                    if ($conteudo !== null && $conteudo !== '') {
                        $sha256 = hash('sha256', $conteudo);
                    }
                    $tamanhoMb = number_format($disk->size($path) / 1048576, 1, ',', '').' MB';
                }

                $videos[] = [
                    'tarefa_label' => $t->tipoLabel(),
                    'data' => $t->executado_em?->format('d/m/Y H:i'),
                    'nome_ficheiro' => basename($path),
                    'tamanho_mb' => $existe ? $tamanhoMb : 'ficheiro não encontrado',
                    'sha256' => $sha256,
                ];
            }
        }

        return $videos;
    }

    /**
     * @param  Collection<int, PoolClosureTask>  $trabalhos
     * @return array<int, array<string, mixed>>
     */
    private function processarDocumentos($trabalhos): array
    {
        $disk = Storage::disk(DailyRecord::getStorageDisk());
        $documentos = [];

        foreach ($trabalhos as $t) {
            if (! is_array($t->documentos) || empty($t->documentos)) {
                continue;
            }

            $dadosJson = is_array($t->dados) ? $t->dados : [];

            foreach ($t->documentos as $path) {
                if (! is_string($path)) {
                    continue;
                }

                $nomeFicheiro = basename($path);
                $sha256 = '—';

                if ($disk->exists($path)) {
                    $conteudo = $disk->get($path);
                    if ($conteudo !== null && $conteudo !== '') {
                        $sha256 = hash('sha256', $conteudo);
                    }
                }

                $documentos[] = [
                    'tarefa_label' => $t->tipoLabel(),
                    'laboratorio' => $dadosJson['laboratorio'] ?? null,
                    'numero_boletim' => $dadosJson['numero_boletim'] ?? null,
                    'data_boletim' => $dadosJson['data_boletim'] ?? $t->executado_em?->format('d/m/Y'),
                    'resultado' => $dadosJson['resultado_legionella'] ?? ($dadosJson['resultado'] ?? 'Conforme'),
                    'nome_ficheiro' => $nomeFicheiro,
                    'sha256' => $sha256,
                ];
            }
        }

        return $documentos;
    }

    /**
     * @return array{total_leituras: int, de: Carbon, ate: Carbon, cobertura_percent: float, pontos: array<string, array{orp: float, temp: float, contagem: int}>}
     */
    private function obterDadosSonda(PoolClosure $encerramento): array
    {
        $de = $encerramento->inicio->copy()->startOfDay();
        $ate = ($encerramento->fim ?? Carbon::now())->copy()->endOfDay();

        $leituras = DB::table('sensor_readings')
            ->where('pool_id', $encerramento->pool_id)
            ->whereBetween('lida_em', [$de, $ate])
            ->orderBy('lida_em')
            ->get(['lida_em', 'orp', 'temperatura_agua']);

        $total = $leituras->count();
        if ($total === 0) {
            return [
                'total_leituras' => 0,
                'de' => $de,
                'ate' => $ate,
                'cobertura_percent' => 0.0,
                'pontos' => [],
            ];
        }

        $minutosTotais = max(1, $de->diffInMinutes($ate));
        $leiturasEsperadas = max(1, (int) ($minutosTotais / 15));
        $cobertura = round(min(100.0, ($total / $leiturasEsperadas) * 100), 1);

        // Agrupar por hora
        $pontos = [];
        foreach ($leituras as $l) {
            $horaKey = Carbon::parse($l->lida_em)->format('Y-m-d H:00');
            if (! isset($pontos[$horaKey])) {
                $pontos[$horaKey] = [
                    'soma_orp' => 0.0,
                    'soma_temp' => 0.0,
                    'contagem' => 0,
                ];
            }

            if ($l->orp !== null) {
                $pontos[$horaKey]['soma_orp'] += (float) $l->orp;
            }
            if ($l->temperatura_agua !== null) {
                $pontos[$horaKey]['soma_temp'] += (float) $l->temperatura_agua;
            }
            $pontos[$horaKey]['contagem']++;
        }

        $mediasHorarias = [];
        foreach ($pontos as $horaKey => $val) {
            $n = max(1, $val['contagem']);
            $mediasHorarias[$horaKey] = [
                'orp' => round($val['soma_orp'] / $n, 1),
                'temp' => round($val['soma_temp'] / $n, 2),
                'contagem' => $val['contagem'],
            ];
        }

        return [
            'total_leituras' => $total,
            'de' => $de,
            'ate' => $ate,
            'cobertura_percent' => $cobertura,
            'pontos' => $mediasHorarias,
        ];
    }

    /**
     * @param  array<string, mixed>  $dadosSonda
     */
    private function gerarGraficoSvg(array $dadosSonda, PoolClosure $encerramento): string
    {
        $pontos = $dadosSonda['pontos'] ?? [];
        if (empty($pontos)) {
            return '';
        }

        $keys = array_keys($pontos);
        $totalPontos = count($keys);
        if ($totalPontos < 2) {
            return '';
        }

        $width = 520;
        $height = 140;
        $padLeft = 35;
        $padRight = 15;
        $padTop = 15;
        $padBottom = 20;

        $plotW = $width - $padLeft - $padRight;
        $plotH = $height - $padTop - $padBottom;

        $piscina = $encerramento->piscina;
        $orpMinBanda = (float) ($piscina->orp_min ?? 650);
        $orpMaxBanda = (float) ($piscina->orp_max ?? 800);

        $tempMinBanda = (float) ($piscina->temp_min ?? 26.0);
        $tempMaxBanda = (float) ($piscina->temp_max ?? 28.0);

        // Escalas
        // Top chart (ORP): Y de 400 a 950 mV (ocupa metade superior)
        $orpMinEscala = 400.0;
        $orpMaxEscala = 950.0;
        $chart1H = (int) ($plotH * 0.48);

        // Bottom chart (Temp): Y de 15 a 35 °C (ocupa metade inferior)
        $tempMinEscala = 15.0;
        $tempMaxEscala = 35.0;
        $chart2Y = $padTop + (int) ($plotH * 0.52);
        $chart2H = (int) ($plotH * 0.48);

        $xFor = fn (int $i): float => $padLeft + ($i / max(1, $totalPontos - 1)) * $plotW;

        $yForOrp = function (float $v) use ($orpMinEscala, $orpMaxEscala, $padTop, $chart1H): float {
            $clamped = max($orpMinEscala, min($orpMaxEscala, $v));
            $ratio = ($clamped - $orpMinEscala) / ($orpMaxEscala - $orpMinEscala);

            return $padTop + (1.0 - $ratio) * $chart1H;
        };

        $yForTemp = function (float $v) use ($tempMinEscala, $tempMaxEscala, $chart2Y, $chart2H): float {
            $clamped = max($tempMinEscala, min($tempMaxEscala, $v));
            $ratio = ($clamped - $tempMinEscala) / ($tempMaxEscala - $tempMinEscala);

            return $chart2Y + (1.0 - $ratio) * $chart2H;
        };

        // Banda ORP
        $bandaOrpY1 = $yForOrp($orpMaxBanda);
        $bandaOrpY2 = $yForOrp($orpMinBanda);
        $bandaOrpH = max(1.0, $bandaOrpY2 - $bandaOrpY1);

        // Banda Temp
        $bandaTempY1 = $yForTemp($tempMaxBanda);
        $bandaTempY2 = $yForTemp($tempMinBanda);
        $bandaTempH = max(1.0, $bandaTempY2 - $bandaTempY1);

        $pathOrp = '';
        $pathTemp = '';

        $i = 0;
        foreach ($pontos as $pt) {
            $x = $xFor($i);
            $yOrp = $yForOrp($pt['orp']);
            $yTemp = $yForTemp($pt['temp']);

            $cmd = $i === 0 ? 'M' : 'L';
            $pathOrp .= "{$cmd} ".round($x, 1).' '.round($yOrp, 1).' ';
            $pathTemp .= "{$cmd} ".round($x, 1).' '.round($yTemp, 1).' ';
            $i++;
        }

        $dataInicio = Carbon::parse($keys[0])->format('d/m');
        $dataFim = Carbon::parse($keys[$totalPontos - 1])->format('d/m');

        $svg = "<svg width=\"{$width}\" height=\"{$height}\" viewBox=\"0 0 {$width} {$height}\" xmlns=\"http://www.w3.org/2000/svg\">\n";
        // Background
        $svg .= "  <rect width=\"{$width}\" height=\"{$height}\" fill=\"#ffffff\" />\n";

        // Chart 1 (ORP) Background & Banda
        $svg .= "  <rect x=\"{$padLeft}\" y=\"{$padTop}\" width=\"{$plotW}\" height=\"{$chart1H}\" fill=\"#f9fafb\" stroke=\"#e5e7eb\" stroke-width=\"0.5\" />\n";
        $svg .= "  <rect x=\"{$padLeft}\" y=\"{$bandaOrpY1}\" width=\"{$plotW}\" height=\"{$bandaOrpH}\" fill=\"#e0f2fe\" opacity=\"0.6\" />\n";
        $svg .= '  <text x="5" y="'.($padTop + 10)."\" font-family=\"DejaVu Sans\" font-size=\"6.5\" fill=\"#1e3a8a\" font-weight=\"bold\">ORP (mV)</text>\n";
        $svg .= '  <text x="5" y="'.($bandaOrpY1 + 4)."\" font-family=\"DejaVu Sans\" font-size=\"5.5\" fill=\"#0369a1\">{$orpMaxBanda}</text>\n";
        $svg .= '  <text x="5" y="'.($bandaOrpY2 + 4)."\" font-family=\"DejaVu Sans\" font-size=\"5.5\" fill=\"#0369a1\">{$orpMinBanda}</text>\n";
        $svg .= "  <path d=\"{$pathOrp}\" fill=\"none\" stroke=\"#0284c7\" stroke-width=\"1.2\" />\n";

        // Chart 2 (Temp) Background & Banda
        $svg .= "  <rect x=\"{$padLeft}\" y=\"{$chart2Y}\" width=\"{$plotW}\" height=\"{$chart2H}\" fill=\"#f9fafb\" stroke=\"#e5e7eb\" stroke-width=\"0.5\" />\n";
        $svg .= "  <rect x=\"{$padLeft}\" y=\"{$bandaTempY1}\" width=\"{$plotW}\" height=\"{$bandaTempH}\" fill=\"#fef3c7\" opacity=\"0.6\" />\n";
        $svg .= '  <text x="5" y="'.($chart2Y + 10)."\" font-family=\"DejaVu Sans\" font-size=\"6.5\" fill=\"#b45309\" font-weight=\"bold\">Temp (°C)</text>\n";
        $svg .= '  <text x="5" y="'.($bandaTempY1 + 4)."\" font-family=\"DejaVu Sans\" font-size=\"5.5\" fill=\"#b45309\">{$tempMaxBanda}</text>\n";
        $svg .= '  <text x="5" y="'.($bandaTempY2 + 4)."\" font-family=\"DejaVu Sans\" font-size=\"5.5\" fill=\"#b45309\">{$tempMinBanda}</text>\n";
        $svg .= "  <path d=\"{$pathTemp}\" fill=\"none\" stroke=\"#d97706\" stroke-width=\"1.2\" />\n";

        // Eixo X Labels
        $yEixo = $height - 5;
        $svg .= "  <text x=\"{$padLeft}\" y=\"{$yEixo}\" font-family=\"DejaVu Sans\" font-size=\"6\" fill=\"#6b7280\">{$dataInicio}</text>\n";
        $svg .= '  <text x="'.($padLeft + $plotW - 20)."\" y=\"{$yEixo}\" font-family=\"DejaVu Sans\" font-size=\"6\" fill=\"#6b7280\">{$dataFim}</text>\n";

        $svg .= '</svg>';

        return $svg;
    }

    private function gerarNomeFicheiro(PoolClosure $encerramento, string $prefixo): string
    {
        $slugPiscina = Str::slug($encerramento->piscina->nome_completo ?? 'piscina');
        $inicio = $encerramento->inicio->format('Ymd');
        $fim = ($encerramento->fim ?? Carbon::now())->format('Ymd');

        return "{$prefixo}_{$slugPiscina}_{$inicio}_{$fim}.pdf";
    }

    private function registarAuditoria(PoolClosure $encerramento, string $evento, string $descricao, ?User $emitente = null): void
    {
        $user = $emitente ?? auth()->user();

        activity('relatorio')
            ->causedBy($user)
            ->performedOn($encerramento)
            ->event($evento)
            ->withProperties([
                'pool_closure_id' => $encerramento->id,
                'pool_id' => $encerramento->pool_id,
                'piscina' => $encerramento->piscina->nome_completo ?? '—',
                'inicio' => $encerramento->inicio->toDateString(),
                'fim' => $encerramento->fim?->toDateString(),
            ])
            ->log($descricao);
    }
}
