<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\RelatorioPdf;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms\Get;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RelatorioPdfObservacoesTest extends TestCase
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
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->installation = Installation::create([
            'name' => 'Complexo Aquático Municipal',
            'morada' => 'Rua das Piscinas 1',
            'active' => true,
        ]);

        $this->pool = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina Principal 25m',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.5,
            'volume' => 600.0,
            'active' => true,
        ]);

        Storage::fake(DailyRecord::getStorageDisk());
    }

    /**
     * Teste 1: O formulário contém os campos de observações gerais e fotos e as secções visíveis por omissão.
     */
    public function test_form_contains_observacoes_and_fotos_fields(): void
    {
        Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->assertSet('data.observacoes_gerais', null)
            ->assertSet('data.fotos_observacoes', []);

        $this->assertContains('mostrar_observacoes_gerais', RelatorioPdf::SECCOES_DGS_OFICIAL);
        $this->assertContains('mostrar_observacoes_gerais', RelatorioPdf::TODAS_SECCOES);
    }

    /**
     * Teste 2: O método de gerar justificação da sonda produz texto fundamentado com normas OMS / DIN 19643.
     */
    public function test_gerar_justificacao_sonda_produces_legally_sound_text(): void
    {
        $inicio = now()->subDays(3)->startOfDay();
        $fim = now()->subDay()->endOfDay();

        SensorReading::create([
            'pool_id' => $this->pool->id,
            'hanna_device_id' => 'device-test-1',
            'ph' => 7.25,
            'orp' => 735.0,
            'temperatura_agua' => 27.5,
            'lida_em' => now()->subDays(2)->setHour(10),
        ]);

        SensorReading::create([
            'pool_id' => $this->pool->id,
            'hanna_device_id' => 'device-test-1',
            'ph' => 7.30,
            'orp' => 715.0,
            'temperatura_agua' => 27.6,
            'lida_em' => now()->subDays(2)->setHour(14),
        ]);

        $mockGet = new class([
            'installation_id' => $this->installation->id,
            'pool_id' => (string) $this->pool->id,
            'data_inicio' => $inicio->toDateString(),
            'data_fim' => $fim->toDateString(),
        ]) extends Get {
            public function __construct(private array $dados) {}

            public function __invoke(\Filament\Forms\Components\Component|string $path = '', bool $isAbsolute = false): mixed
            {
                $key = is_string($path) ? $path : $path->getName();

                return $this->dados[$key] ?? null;
            }
        };

        $texto = RelatorioPdf::gerarJustificacaoSonda($mockGet);

        $this->assertNotNull($texto);
        $this->assertStringContainsString('Garantia de Desinfeção Contínua', $texto);
        $this->assertStringContainsString('OMS', $texto);
        $this->assertStringContainsString('DIN 19643', $texto);
        $this->assertStringContainsString('650 mV', $texto);
        $this->assertStringContainsString('725 mV', $texto); // média (735 + 715) / 2
    }

    /**
     * Teste 3: Processamento de fotografias para Data URIs em base64.
     */
    public function test_processar_fotos_observacoes_converts_to_base64(): void
    {
        $component = new RelatorioPdf;

        $fakeFile = UploadedFile::fake()->createWithContent('bomba_doseadora.jpg', 'fake-jpeg-content');

        $resultado = $component->processarFotosObservacoes([$fakeFile]);

        $this->assertCount(1, $resultado);
        $this->assertEquals('bomba_doseadora.jpg', $resultado[0]['nome']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $resultado[0]['base64']);
    }

    /**
     * Teste 4: Template do Livro Sanitário imprime o bloco de observações e fotos quando preenchido.
     */
    public function test_livro_sanitario_blade_renders_observations_and_photo_grid(): void
    {
        $fakeImg = UploadedFile::fake()->createWithContent('filtro_novo.png', 'fake-png-content');
        $component = new RelatorioPdf;
        $fotos = $component->processarFotosObservacoes([$fakeImg]);

        $html = view('pdf.livro-sanitario', [
            'instalacao' => $this->installation,
            'seccoes' => [
                [
                    'piscina' => $this->pool,
                    'registos' => collect(),
                    'controlador' => collect(),
                    'acoesOperacionais' => collect(),
                ],
            ],
            'inicio' => now()->subDays(5),
            'fim' => now()->subDay(),
            'emitidoEm' => now(),
            'emitidoPor' => 'Técnico de Teste',
            'colunasVisiveis' => RelatorioPdf::COLUNAS_DGS_OFICIAL,
            'seccoesVisiveis' => RelatorioPdf::SECCOES_DGS_OFICIAL,
            'modo' => 'todos',
            'controladorModo' => 'media_diaria',
            'observacoesGerais' => 'Substituição da válvula seletora às 09h30, parâmetros repostos a 100%.',
            'fotosObservacoes' => $fotos,
        ])->render();

        $this->assertStringContainsString('Observações Gerais e Justificações Técnicas do Relatório', $html);
        $this->assertStringContainsString('Substituição da válvula seletora às 09h30, parâmetros repostos a 100%.', $html);
        $this->assertStringContainsString('Critério Sanitário de Eficácia da Desinfeção (Norma OMS / DIN 19643)', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('filtro_novo.png', $html);
    }

    /**
     * Teste 5: Desmarcar a secção em seccoes_visiveis omite o bloco no PDF mesmo se preenchido.
     */
    public function test_omitting_section_hides_observations_block(): void
    {
        $html = view('pdf.livro-sanitario', [
            'instalacao' => $this->installation,
            'seccoes' => [
                [
                    'piscina' => $this->pool,
                    'registos' => collect(),
                    'controlador' => collect(),
                    'acoesOperacionais' => collect(),
                ],
            ],
            'inicio' => now()->subDays(5),
            'fim' => now()->subDay(),
            'emitidoEm' => now(),
            'emitidoPor' => 'Técnico de Teste',
            'colunasVisiveis' => RelatorioPdf::COLUNAS_DGS_OFICIAL,
            // Remove 'mostrar_observacoes_gerais'
            'seccoesVisiveis' => ['mostrar_resumo', 'mostrar_assinaturas'],
            'modo' => 'todos',
            'controladorModo' => 'media_diaria',
            'observacoesGerais' => 'Nota confidencial interna que não deve sair no PDF.',
            'fotosObservacoes' => [],
        ])->render();

        $this->assertStringNotContainsString('Observações Gerais e Justificações Técnicas do Relatório', $html);
        $this->assertStringNotContainsString('Nota confidencial interna que não deve sair no PDF.', $html);
    }

    /**
     * Teste 6: Preflight summary deteta quando existem observações gerais e fotos.
     */
    public function test_preflight_summary_reflects_observacoes_and_fotos(): void
    {
        $test = Livewire::actingAs($this->admin)
            ->test(RelatorioPdf::class)
            ->set('data.observacoes_gerais', 'Tudo conforme')
            ->set('data.fotos_observacoes', ['relatorios/observacoes/foto1.jpg', 'relatorios/observacoes/foto2.jpg']);

        $summary = $test->instance()->preflightSummary;

        $this->assertTrue($summary['temObservacoes']);
        $this->assertEquals(2, $summary['fotosCount']);
    }
}
