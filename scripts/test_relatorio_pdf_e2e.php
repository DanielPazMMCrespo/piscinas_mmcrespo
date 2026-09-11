<?php

declare(strict_types=1);

/**
 * Script de Teste Ponta a Ponta (E2E) Prático e Exaustivo:
 * Validação do Relatório Sanitário PDF com Sonda Contínua e Fotos de Observações.
 *
 * Execução: php scripts/test_relatorio_pdf_e2e.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "\n====================================================================\n";
echo " TESTE PRÁTICO PONTA A PONTA (E2E) - LIVRO DE REGISTO SANITÁRIO PDF \n";
echo "====================================================================\n\n";

putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('CACHE_STORE=array');
putenv('CACHE_DRIVER=array');

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

use App\Filament\Pages\RelatorioPdf;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use App\Support\PdfRenderer;
use Carbon\Carbon;
use Filament\Forms\Components\Component;
use Filament\Forms\Get;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$checks = [];
function recordCheck(string $description, bool $passed, ?string $detail = null): void
{
    global $checks;
    $checks[] = ['desc' => $description, 'passed' => $passed, 'detail' => $detail];
    $status = $passed ? "\033[32m[PASSOU]\033[0m" : "\033[31m[FALHOU]\033[0m";
    echo sprintf("  %s %s%s\n", $status, $description, $detail ? " -> {$detail}" : '');
}

try {
    echo "1. Inicialização do ambiente e base de dados SQLite isolada...\n";
    Artisan::call('migrate', ['--force' => true]);
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
    Storage::fake(DailyRecord::getStorageDisk());
    recordCheck('Base de dados migrada em memória', true);

    echo "\n2. Criação de entidades de teste (Instalação, Piscina Olímpica, Utilizador)...\n";
    $admin = User::factory()->create([
        'name' => 'Eng. Daniel Paz',
        'email' => 'daniel.paz@mmcrespo.pt',
    ]);
    $admin->assignRole('admin');

    $installation = Installation::create([
        'name' => 'Complexo de Piscinas Municipais MMCrespo',
        'morada' => 'Avenida dos Desportos 42, Leiria',
        'active' => true,
    ]);

    $pool = Pool::create([
        'installation_id' => $installation->id,
        'name' => 'Piscina Olímpica 50m',
        'type' => 'competition',
        'temp_min' => 26.0,
        'temp_max' => 28.5,
        'volume' => 1250.0,
        'active' => true,
    ]);
    recordCheck('Instalação e Piscina Olímpica criadas', true, "ID Piscina: {$pool->id}");

    echo "\n3. Injeção de cenário operacional real:\n";
    echo "   - 72 leituras contínuas da sonda Hanna BL132 (ORP ~720 mV, pH 7.24)\n";
    echo "   - Registo manual às 08h00 com cloro livre baixo (0.40 mg/L) por quebra noturna\n";
    echo "   - Registo manual corretivo às 08h30 com cloro livre reposto (1.45 mg/L)\n";

    $inicio = Carbon::create(2026, 9, 8, 0, 0, 0);
    $fim = Carbon::create(2026, 9, 10, 23, 59, 59);

    $orpValores = [718.0, 722.0, 720.5, 725.0, 715.0, 724.0, 719.5, 721.0];
    $horaAtual = $inicio->copy();
    $leituraCount = 0;
    while ($horaAtual->lte($fim)) {
        SensorReading::create([
            'pool_id' => $pool->id,
            'hanna_device_id' => 'HANNA-BL132-PROD-01',
            'ph' => 7.24,
            'orp' => $orpValores[$leituraCount % count($orpValores)],
            'temperatura_agua' => 27.2,
            'lida_em' => $horaAtual->copy(),
        ]);
        $horaAtual->addHour();
        $leituraCount++;
    }
    recordCheck('72 leituras de sonda automática inseridas', $leituraCount === 72, "Total: {$leituraCount} leituras");

    $registoAnomalo = DailyRecord::create([
        'pool_id' => $pool->id,
        'user_id' => $admin->id,
        'registado_em' => Carbon::create(2026, 9, 8, 8, 0, 0),
        'ph' => 7.20,
        'cloro_livre' => 0.40,
        'cloro_total' => 0.55,
        'temperatura' => 27.0,
        'transparencia' => 'Límpida',
        'contador_valor' => 12450.5,
        'banhistas' => 0,
        'observacoes' => 'Análise de abertura: Doseador noturno desferrou. Manutenção em curso.',
        'acao_corretiva' => 'Ferragem imediata da bomba doseadora e reforço de hipoclorito às 08h10.',
    ]);

    $registoConforme = DailyRecord::create([
        'pool_id' => $pool->id,
        'user_id' => $admin->id,
        'registado_em' => Carbon::create(2026, 9, 8, 8, 30, 0),
        'ph' => 7.22,
        'cloro_livre' => 1.45,
        'cloro_total' => 1.60,
        'temperatura' => 27.1,
        'transparencia' => 'Límpida',
        'contador_valor' => 12451.0,
        'banhistas' => 18,
        'observacoes' => 'Parâmetros totalmente estabilizados.',
    ]);
    recordCheck('Registos manuais às 08h00 e 08h30 criados', true);

    echo "\n4. Teste de geração automática da Justificação Técnica da Sonda (gerarJustificacaoSonda)...\n";
    $getter = new class(['installation_id' => $installation->id, 'pool_id' => (string) $pool->id, 'data_inicio' => $inicio->toDateString(), 'data_fim' => $fim->toDateString()]) extends Get
    {
        public function __construct(private array $dados) {}

        public function __invoke(Component|string $path = '', bool $isAbsolute = false): mixed
        {
            $key = is_string($path) ? $path : $path->getName();

            return $this->dados[$key] ?? null;
        }
    };

    $justificacao = RelatorioPdf::gerarJustificacaoSonda($getter);
    $temOMS = str_contains($justificacao, 'OMS') && str_contains($justificacao, 'DIN 19643');
    $temORP = str_contains($justificacao, '721 mV') && str_contains($justificacao, '650 mV');
    $temMatinal = str_contains($justificacao, 'menos de 30 minutos');
    recordCheck('Justificação técnica automática gerada', $temOMS && $temORP && $temMatinal, 'Normas OMS, DIN 19643, ORP 721 mV e justificação matinal presentes');

    echo "\n5. Processamento de evidências fotográficas em Base64...\n";
    $jpegBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

    $fakeUpload1 = UploadedFile::fake()->createWithContent('bomba_doseadora_substituida.jpg', $jpegBytes);
    $fakeUpload2 = UploadedFile::fake()->createWithContent('fotometro_cloro_conforme.png', $pngBytes);

    $page = new RelatorioPdf;
    $fotosBase64 = $page->processarFotosObservacoes([$fakeUpload1, $fakeUpload2]);
    $fotosValidas = count($fotosBase64) === 2 &&
        str_starts_with($fotosBase64[0]['base64'], 'data:image/jpeg;base64,') &&
        str_starts_with($fotosBase64[1]['base64'], 'data:image/png;base64,');
    recordCheck('Fotos convertidas para Data URIs em base64', $fotosValidas, '2 fotos (JPEG + PNG)');

    echo "\n6. Construção da estrutura das secções do relatório (construirSeccoes)...\n";
    $piscinas = collect([$pool]);
    $seccoes = RelatorioPdf::construirSeccoes($piscinas, $inicio, $fim, 'todos', 'media_diaria');
    $seccoesValidas = count($seccoes) === 1 && count($seccoes[0]['registos']) === 2 && count($seccoes[0]['controlador']) >= 1;
    recordCheck('Secções agregadas com sucesso', $seccoesValidas, '1 secção de piscina com 2 registos manuais e agregados do controlador');

    echo "\n7. Renderização efetiva do PDF completo via Dompdf (PdfRenderer)...\n";
    $memInicial = memory_get_usage(true);
    $tInicio = microtime(true);

    $dompdf = PdfRenderer::render('pdf.livro-sanitario', [
        'instalacao' => $installation,
        'seccoes' => $seccoes,
        'inicio' => $inicio,
        'fim' => $fim,
        'emitidoEm' => now(),
        'emitidoPor' => $admin->name,
        'colunasVisiveis' => RelatorioPdf::TODAS_COLUNAS,
        'seccoesVisiveis' => array_merge(RelatorioPdf::TODAS_SECCOES, ['mostrar_observacoes_gerais']),
        'modo' => 'todos',
        'controladorModo' => 'media_diaria',
        'observacoesGerais' => $justificacao."\n\nObservação Operacional: Bombas e filtros inspecionados às 08h15.",
        'fotosObservacoes' => $fotosBase64,
    ]);

    $tempoRender = microtime(true) - $tInicio;
    $memFinal = memory_get_peak_usage(true);
    $deltaMemMB = round(($memFinal - $memInicial) / (1024 * 1024), 2);
    $pdfOutput = $dompdf->output();
    $pdfBytes = strlen($pdfOutput);

    $viewHtml = view('pdf.livro-sanitario', [
        'instalacao' => $installation,
        'seccoes' => $seccoes,
        'inicio' => $inicio,
        'fim' => $fim,
        'emitidoEm' => now(),
        'emitidoPor' => $admin->name,
        'colunasVisiveis' => RelatorioPdf::TODAS_COLUNAS,
        'seccoesVisiveis' => array_merge(RelatorioPdf::TODAS_SECCOES, ['mostrar_observacoes_gerais']),
        'modo' => 'todos',
        'controladorModo' => 'media_diaria',
        'observacoesGerais' => $justificacao."\n\nObservação Operacional: Bombas e filtros inspecionados às 08h15.",
        'fotosObservacoes' => $fotosBase64,
    ])->render();

    $temColunaConformeSonda = str_contains($viewHtml, '>Conforme</th>');
    $temConformidadeResumo = str_contains($viewHtml, 'Conformidade Sonda (pH e ORP): <strong>100,0%</strong>');
    recordCheck('Coluna Conforme e taxa de conformidade da sonda (pH e ORP)', $temColunaConformeSonda && $temConformidadeResumo, 'Coluna Conforme e 100,0% presentes');

    echo "\n8. Auditoria binária e estrutural do ficheiro PDF gerado...\n";
    $temHeader = str_starts_with($pdfOutput, '%PDF-');
    $temTrailer = str_contains($pdfOutput, '%%EOF');
    $tamanhoOk = $pdfBytes > 10000;
    $pageCount = $dompdf->getCanvas()->get_page_count();
    $temPaginas = $pageCount >= 1;

    recordCheck('Cabeçalho oficial PDF (%PDF-)', $temHeader, substr($pdfOutput, 0, 8));
    recordCheck('Terminador de ficheiro PDF (%%EOF)', $temTrailer);
    recordCheck('Dimensão binária do PDF', $tamanhoOk, sprintf('%s bytes (~%.1f KB)', number_format($pdfBytes, 0, ',', '.'), $pdfBytes / 1024));
    recordCheck('Numeração dinâmica de páginas (Canvas Dompdf)', $temPaginas, "Total de páginas: {$pageCount}");

    // Guardar para auditoria física
    $ficheiroDestino = storage_path('app/relatorio_e2e_validacao.pdf');
    file_put_contents($ficheiroDestino, $pdfOutput);
    recordCheck('Ficheiro guardado em disco para inspeção', file_exists($ficheiroDestino), $ficheiroDestino);

    echo "\n====================================================================\n";
    $totalChecks = count($checks);
    $passedChecks = count(array_filter($checks, fn ($c) => $c['passed']));
    $conformidade = round(($passedChecks / $totalChecks) * 100, 1);

    echo sprintf("RESULTADO FINAL: %d/%d verificações passaram com êxito.\n", $passedChecks, $totalChecks);
    echo sprintf("CLASSIFICAÇÃO DE CONFORMIDADE PRÁTICA: %.1f%%\n", $conformidade);
    echo "====================================================================\n\n";

    if ($conformidade < 100.0) {
        exit(1);
    }
    exit(0);

} catch (Throwable $e) {
    echo "\n\033[31m[ERRO CRÍTICO]: ".$e->getMessage()."\033[0m\n";
    echo $e->getTraceAsString()."\n";
    exit(1);
}
