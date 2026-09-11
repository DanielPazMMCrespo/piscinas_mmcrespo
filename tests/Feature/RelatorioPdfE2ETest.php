<?php

declare(strict_types=1);

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Teste End-to-End (E2E) prático e exaustivo:
 * Simula o cenário real de um complexo de piscinas com:
 * 1. Sonda automática com leituras contínuas (ORP ~720 mV, DIN 19643 / OMS).
 * 2. Análise manual matinal às 08h00 com quebra pontual de cloro livre (0.40 mg/L).
 * 3. Geração automática de fundamentação técnica via gerarJustificacaoSonda.
 * 4. Inclusão de fotos de evidência técnica em base64 e observações do operador.
 * 5. Renderização completa em Dompdf (PdfRenderer) e validação binária de integridade (%PDF-).
 */
class RelatorioPdfE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Installation $installation;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['name' => 'Eng. Daniel Paz']);
        $this->admin->assignRole('admin');

        $this->installation = Installation::create([
            'name' => 'Complexo de Piscinas Municipais MMCrespo',
            'morada' => 'Avenida dos Desportos 42, Leiria',
            'active' => true,
        ]);

        $this->pool = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina Olímpica 50m',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.5,
            'volume' => 1250.0,
            'active' => true,
        ]);

        Storage::fake(DailyRecord::getStorageDisk());
    }

    public function test_e2e_scenario_low_chlorine_with_high_orp_and_dompdf_rendering(): void
    {
        $inicio = Carbon::create(2026, 9, 8, 0, 0, 0);
        $fim = Carbon::create(2026, 9, 10, 23, 59, 59);

        // 1. Simular 72 leituras contínuas da sonda com ORP ~720 mV
        $orpValores = [718.0, 722.0, 720.5, 725.0, 715.0, 724.0, 719.5, 721.0];
        $horaAtual = $inicio->copy();
        $leituraIdx = 0;
        while ($horaAtual->lte($fim)) {
            SensorReading::create([
                'pool_id' => $this->pool->id,
                'hanna_device_id' => 'HANNA-BL132-E2E',
                'ph' => 7.24,
                'orp' => $orpValores[$leituraIdx % count($orpValores)],
                'temperatura_agua' => 27.2,
                'lida_em' => $horaAtual->copy(),
            ]);
            $horaAtual->addHour();
            $leituraIdx++;
        }

        // 2. Simular registo manual com cloro livre baixo (0.40 mg/L) às 08h00 do dia 08/09/2026
        DailyRecord::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->admin->id,
            'registado_em' => Carbon::create(2026, 9, 8, 8, 0, 0),
            'ph' => 7.20,
            'cloro_livre' => 0.40,
            'cloro_total' => 0.55,
            'temperatura' => 27.0,
            'transparencia' => 'Límpida',
            'contador_valor' => 12450.5,
            'banhistas' => 0,
            'observacoes' => 'Quebra noturna de doseamento detetada na abertura.',
            'acao_corretiva' => 'Substituição imediata de mangueira de doseamento e injeção rápida de hipoclorito.',
        ]);

        // Registo de controlo às 08h30 com reposição de cloro
        DailyRecord::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->admin->id,
            'registado_em' => Carbon::create(2026, 9, 8, 8, 30, 0),
            'ph' => 7.22,
            'cloro_livre' => 1.45,
            'cloro_total' => 1.60,
            'temperatura' => 27.1,
            'transparencia' => 'Límpida',
            'contador_valor' => 12451.0,
            'banhistas' => 15,
            'observacoes' => 'Parâmetros totalmente estabilizados.',
        ]);

        // 3. Testar a geração automática da justificação técnica da sonda
        $getter = new class(['installation_id' => $this->installation->id, 'pool_id' => (string) $this->pool->id, 'data_inicio' => $inicio->toDateString(), 'data_fim' => $fim->toDateString()]) extends Get
        {
            public function __construct(private array $dados) {}

            public function __invoke(Component|string $path = '', bool $isAbsolute = false): mixed
            {
                $key = is_string($path) ? $path : $path->getName();

                return $this->dados[$key] ?? null;
            }
        };

        $justificacaoAutomatica = RelatorioPdf::gerarJustificacaoSonda($getter);

        $this->assertNotEmpty($justificacaoAutomatica);
        $this->assertStringContainsString('Garantia de Desinfeção Contínua', $justificacaoAutomatica);
        $this->assertStringContainsString('OMS', $justificacaoAutomatica);
        $this->assertStringContainsString('DIN 19643', $justificacaoAutomatica);
        $this->assertStringContainsString('721 mV', $justificacaoAutomatica); // Média arredondada
        $this->assertStringContainsString('650 mV', $justificacaoAutomatica);
        $this->assertStringContainsString('menos de 30 minutos', $justificacaoAutomatica);

        // 4. Preparar fotografias de evidência técnica (JPEG e PNG base64)
        $jpegBase64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
        $jpegBytes = base64_decode($jpegBase64);
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $fakeUpload1 = UploadedFile::fake()->createWithContent('bomba_doseadora_reparada.jpg', $jpegBytes);
        $fakeUpload2 = UploadedFile::fake()->createWithContent('analise_fotometro_conforme.png', $pngBytes);

        $page = new RelatorioPdf;
        $fotosBase64 = $page->processarFotosObservacoes([$fakeUpload1, $fakeUpload2]);

        $this->assertCount(2, $fotosBase64);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $fotosBase64[0]['base64']);
        $this->assertStringStartsWith('data:image/png;base64,', $fotosBase64[1]['base64']);

        // 5. Construir secções através da lógica oficial da página
        $piscinas = collect([$this->pool]);
        $seccoes = RelatorioPdf::construirSeccoes($piscinas, $inicio, $fim, 'todos', 'media_diaria');

        $this->assertCount(1, $seccoes);
        $this->assertCount(2, $seccoes[0]['registos']);
        $this->assertNotEmpty($seccoes[0]['controlador']);

        // 6. Renderizar efetivamente o PDF completo com Dompdf (PdfRenderer)
        $startTime = microtime(true);

        $dompdf = PdfRenderer::render('pdf.livro-sanitario', [
            'instalacao' => $this->installation,
            'seccoes' => $seccoes,
            'inicio' => $inicio,
            'fim' => $fim,
            'emitidoEm' => now(),
            'emitidoPor' => $this->admin->name,
            'colunasVisiveis' => RelatorioPdf::TODAS_COLUNAS,
            'seccoesVisiveis' => array_merge(RelatorioPdf::TODAS_SECCOES, ['mostrar_observacoes_gerais']),
            'modo' => 'todos',
            'controladorModo' => 'media_diaria',
            'observacoesGerais' => $justificacaoAutomatica."\n\nNota Adicional: Verificadas bombas doseadoras e filtros às 08h15.",
            'fotosObservacoes' => $fotosBase64,
        ]);

        $duration = microtime(true) - $startTime;
        $pdfOutput = $dompdf->output();

        // 7. Validações estritas de bytes, integridade PDF e ausência de erros
        $this->assertGreaterThan(5000, strlen($pdfOutput), 'O PDF gerado deve ter tamanho substancial superior a 5 KB');
        $this->assertStringStartsWith('%PDF-', $pdfOutput, 'O ficheiro gerado tem de ter o cabeçalho oficial %PDF-');
        $this->assertStringContainsString('%%EOF', $pdfOutput, 'O ficheiro gerado tem de ter o marcador de fim %%EOF');

        // Confirmação de tempos de resposta e uso de memória
        $this->assertLessThan(15.0, $duration, 'A renderização do Dompdf deve demorar menos de 15 segundos');

        // Guardar o PDF em disco de teste para auditoria
        $pdfPath = storage_path('app/relatorio_e2e_validacao.pdf');
        file_put_contents($pdfPath, $pdfOutput);
        $this->assertFileExists($pdfPath);
    }
}
