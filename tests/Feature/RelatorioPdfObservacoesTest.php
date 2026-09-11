<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\RelatorioPdf;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use Filament\Forms\Components\Component;
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

        $mockGet = new class(['installation_id' => $this->installation->id, 'pool_id' => (string) $this->pool->id, 'data_inicio' => $inicio->toDateString(), 'data_fim' => $fim->toDateString()]) extends Get
        {
            public function __construct(private array $dados) {}

            public function __invoke(Component|string $path = '', bool $isAbsolute = false): mixed
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

    /**
     * Teste 7: A justificação técnica e observações aparecem no TOPO do relatório antes das tabelas/piscinas.
     */
    public function test_justificacao_tecnica_appears_at_top_before_pools(): void
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
            'seccoesVisiveis' => RelatorioPdf::SECCOES_DGS_OFICIAL,
            'modo' => 'todos',
            'controladorModo' => 'media_diaria',
            'observacoesGerais' => 'Justificação prioritária no topo.',
            'fotosObservacoes' => [],
        ])->render();

        $posObservacoes = strpos($html, 'Observações Gerais e Justificações Técnicas do Relatório');
        $posPiscina = strpos($html, '<p class="info-piscina">');

        $this->assertNotFalse($posObservacoes);
        $this->assertNotFalse($posPiscina);
        $this->assertLessThan($posPiscina, $posObservacoes, 'O bloco de justificações deve surgir ANTES da secção da piscina (no topo do relatório).');
    }

    /**
     * Teste 8: Tabela do controlador apresenta a coluna Cloro Conf. (ORP) e avaliação de conformidade.
     */
    public function test_controlador_table_renders_cloro_orp_conforme_and_cl_manual(): void
    {
        $leituraConforme = (object) [
            'dia' => '2026-09-08',
            'leituras' => 24,
            'ph_avg' => 7.25,
            'ph_min' => 7.20,
            'ph_max' => 7.30,
            'orp_avg' => 735.0, // Dentro de 650-850 mV
            'manual_cloro_livre' => 1.50, // Conforme
            'temp_avg' => 26.5,
            'sem_leitura_valida' => false,
            'motivo_exclusao' => null,
        ];

        $leituraNaoConforme = (object) [
            'dia' => '2026-09-09',
            'leituras' => 24,
            'ph_avg' => 7.20,
            'ph_min' => 7.15,
            'ph_max' => 7.25,
            'orp_avg' => 580.0, // Fora de 650-850 mV
            'manual_cloro_livre' => 0.40, // Fora da banda
            'temp_avg' => 26.5,
            'sem_leitura_valida' => false,
            'motivo_exclusao' => null,
        ];

        $html = view('pdf.livro-sanitario', [
            'instalacao' => $this->installation,
            'seccoes' => [
                [
                    'piscina' => $this->pool,
                    'registos' => collect(),
                    'controlador' => collect([$leituraConforme, $leituraNaoConforme]),
                    'acoesOperacionais' => collect(),
                ],
            ],
            'inicio' => now()->subDays(5),
            'fim' => now()->subDay(),
            'emitidoEm' => now(),
            'emitidoPor' => 'Técnico de Teste',
            'colunasVisiveis' => RelatorioPdf::COLUNAS_DGS_OFICIAL,
            'seccoesVisiveis' => array_merge(RelatorioPdf::SECCOES_DGS_OFICIAL, ['mostrar_controlador_tabela']),
            'modo' => 'todos',
            'controladorModo' => 'media_diaria',
            'observacoesGerais' => null,
            'fotosObservacoes' => [],
        ])->render();

        $this->assertStringContainsString('Cloro Conf. (ORP)', $html);
        $this->assertStringContainsString('Banda ORP (OMS / DIN 19643)', $html);
        $this->assertStringContainsString('735', $html);
        $this->assertStringContainsString('580', $html);
        $this->assertStringContainsString('fora-gama', $html);
    }

    /**
     * Teste 9: Tabela principal de registos suporta a coluna ORP quando selecionada.
     */
    public function test_main_table_renders_orp_column_when_selected(): void
    {
        $registo = new DailyRecord([
            'pool_id' => $this->pool->id,
            'registado_em' => now()->subDays(2),
            'ph' => 7.2,
            'cloro_livre' => 1.5,
            'temperatura' => 26.5,
            'orp' => 740,
        ]);
        $registo->id = 9999;
        $registo->setRelation('piscina', $this->pool);
        $registo->setRelation('adicoes', collect());

        $html = view('pdf.livro-sanitario', [
            'instalacao' => $this->installation,
            'seccoes' => [
                [
                    'piscina' => $this->pool,
                    'registos' => collect([$registo]),
                    'controlador' => collect(),
                    'acoesOperacionais' => collect(),
                ],
            ],
            'inicio' => now()->subDays(5),
            'fim' => now()->subDay(),
            'emitidoEm' => now(),
            'emitidoPor' => 'Técnico de Teste',
            'colunasVisiveis' => array_merge(RelatorioPdf::COLUNAS_DGS_OFICIAL, ['orp']),
            'seccoesVisiveis' => RelatorioPdf::SECCOES_DGS_OFICIAL,
            'modo' => 'todos',
            'controladorModo' => 'media_diaria',
            'observacoesGerais' => null,
            'fotosObservacoes' => [],
        ])->render();

        $this->assertStringContainsString('<th>ORP (mV)</th>', $html);
        $this->assertStringContainsString('740', $html);
    }
}
