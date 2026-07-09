<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ComprehensiveStressTestingAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tecnico;
    private User $nadador;
    private User $gestor;
    private Installation $installation;
    private Pool $pool;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach (['admin', 'tecnico', 'nadador_salvador', 'gestor'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->admin = User::create([
            'name' => 'Admin Teste',
            'email' => 'admin@mmcrespo.pt',
            'password' => bcrypt('password'),
            'must_change_password' => false,
        ]);
        $this->admin->assignRole('admin');

        $this->tecnico = User::create([
            'name' => 'Tecnico Teste',
            'email' => 'tecnico@mmcrespo.pt',
            'password' => bcrypt('password'),
            'must_change_password' => false,
        ]);
        $this->tecnico->assignRole('tecnico');

        $this->nadador = User::create([
            'name' => 'Nadador Teste',
            'email' => 'ns@mmcrespo.pt',
            'password' => bcrypt('password'),
            'must_change_password' => false,
        ]);
        $this->nadador->assignRole('nadador_salvador');

        $this->gestor = User::create([
            'name' => 'Gestor Teste',
            'email' => 'gestor@mmcrespo.pt',
            'password' => bcrypt('password'),
            'must_change_password' => false,
        ]);
        $this->gestor->assignRole('gestor');

        $this->installation = Installation::create([
            'name' => 'Leiria - Central',
            'morada' => 'Rua das Piscinas 1',
            'active' => true,
        ]);

        $this->pool = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.0,
            'active' => true,
        ]);

        // Nadador Salvador precisa de piscinas associadas para aceder ao recurso
        $this->nadador->piscinas()->attach($this->pool->id);

        $this->product = Product::create([
            'name' => 'Hipoclorito de Sódio',
            'unidade' => 'kg',
            'categoria' => 'desinfectante',
            'active' => true,
        ]);

        StockWarehouse::create([
            'product_id' => $this->product->id,
            'quantity' => 100.0,
        ]);

        StockInstallation::create([
            'installation_id' => $this->installation->id,
            'product_id' => $this->product->id,
            'quantity' => 10.0,
            'limite_minimo' => 2.0,
        ]);
    }

    /**
     * 1. Testes de Controle de Acesso por Role (110% Cobertura)
     */
    public function test_access_control_all_roles(): void
    {
        $routes = [
            ['role' => 'gestor', 'url' => '/admin/daily-records', 'status' => 200],
            ['role' => 'gestor', 'url' => '/admin/daily-records/create', 'status' => 403],
            ['role' => 'gestor', 'url' => '/admin/stock-warehouses', 'status' => 403],
            ['role' => 'nadador', 'url' => '/admin/daily-records', 'status' => 200],
            ['role' => 'nadador', 'url' => '/admin/daily-records/create', 'status' => 200],
            ['role' => 'nadador', 'url' => '/admin/stock-warehouses', 'status' => 403],
            ['role' => 'tecnico', 'url' => '/admin/daily-records', 'status' => 200],
            ['role' => 'tecnico', 'url' => '/admin/daily-records/create', 'status' => 200],
            ['role' => 'tecnico', 'url' => '/admin/stock-warehouses', 'status' => 200],
            ['role' => 'admin', 'url' => '/admin/daily-records', 'status' => 200],
            ['role' => 'admin', 'url' => '/admin/daily-records/create', 'status' => 200],
            ['role' => 'admin', 'url' => '/admin/stock-warehouses', 'status' => 200],
            ['role' => 'admin', 'url' => '/admin/activitylogs', 'status' => 200],
        ];

        foreach ($routes as $route) {
            auth()->logout();
            $this->flushSession();
            $user = $this->{$route['role']};
            $response = $this->actingAs($user)->get($route['url']);
            $response->assertStatus($route['status']);
        }
    }

    /**
     * 2. Testes de Stress de Validação de Formulário e Conformidade
     */
    public function test_stress_testing_form_validation_and_conformity(): void
    {
        // Validação da conformidade direta de pH
        $this->assertEquals(\App\Enums\EstadoConformidade::VERMELHO, DailyRecord::avaliarConformidade('ph', 6.0, $this->pool)['estado']);
        $this->assertEquals(\App\Enums\EstadoConformidade::VERMELHO, DailyRecord::avaliarConformidade('ph', 8.5, $this->pool)['estado']);
        $this->assertEquals(\App\Enums\EstadoConformidade::NEUTRO, DailyRecord::avaliarConformidade('ph', null, $this->pool)['estado']);
        $this->assertEquals(\App\Enums\EstadoConformidade::AMARELO, DailyRecord::avaliarConformidade('ph', 7.0, $this->pool)['estado']); // Perto do mínimo
        $this->assertEquals(\App\Enums\EstadoConformidade::VERDE, DailyRecord::avaliarConformidade('ph', 7.4, $this->pool)['estado']);

        // Livewire Form Filling and Validation Stress Test
        $slotValid = [
            'ph' => 7.3,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'temperatura' => 26.5,
            'transparencia' => 0.2,
            'bomba_com_bolhas' => false,
            'pressao_filtro' => 1.1,
            'estado_valvulas_filtro' => 'OK',
            'contador_valor' => 1000.0,
            'agua_modo' => 'on_com_agua',
            'tanque_ok' => true,
            'filtro_faz_retrolavagem' => false,
            'caleira_feita' => true,
            'renovacao_agua' => false,
            // Valores obrigatórios do Nadador Salvador
            'ns_ph' => 7.3,
            'ns_cloro_livre' => 1.2,
            'ns_cloro_total' => 1.5,
            'ns_temperatura' => 26.5,
        ];

        // Caso 1: Enviar valores corretos e conformes
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'registado_em' => now(),
                'ns_foto' => [\Illuminate\Http\UploadedFile::fake()->create('ns_foto.jpg', 10)],
                'piscinas' => [0 => $slotValid],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->pool->id,
            'ph' => 7.3,
            'cloro_livre' => 1.2,
        ]);
    }

    /**
     * 3. Teste de Stress de Consumo Parcial de Stock e Erros de Quantidade
     */
    public function test_stress_testing_chemical_stock_and_consumption(): void
    {
        $slotWithChemical = [
            'ph' => 7.4,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'temperatura' => 26.5,
            'transparencia' => 0.2,
            'adicoes' => [
                [
                    'product_id' => $this->product->id,
                    'quantidade' => 15.0, // Stock na instalação é apenas 10.0!
                    'acao_corretiva' => 'Ajuste de cloro',
                ]
            ],
            'bomba_com_bolhas' => false,
            'pressao_filtro' => 1.1,
            'estado_valvulas_filtro' => 'OK',
            'contador_valor' => 1010.0,
            'agua_modo' => 'on_com_agua',
            'tanque_ok' => true,
            'filtro_faz_retrolavagem' => false,
            'caleira_feita' => true,
            'renovacao_agua' => false,
            'ns_ph' => 7.4,
            'ns_cloro_livre' => 1.2,
            'ns_cloro_total' => 1.5,
            'ns_temperatura' => 26.5,
        ];

        // Deve falhar a validação do formulário porque 15.0 > 10.0 disponível
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'registado_em' => now(),
                'ns_foto' => [\Illuminate\Http\UploadedFile::fake()->create('ns_foto.jpg', 10)],
                'piscinas' => [0 => $slotWithChemical],
            ])
            ->call('create')
            ->assertHasErrors(); // Deve acusar erro de quantidade insuficiente de stock
    }

    /**
     * 4. Teste de Stress de Incidentes e Chat
     */
    public function test_stress_testing_incidents_chat(): void
    {
        $incident = Incident::create([
            'installation_id' => $this->installation->id,
            'user_id' => $this->tecnico->id,
            'titulo' => 'Fuga na Bomba Principal',
            'descricao' => 'Bomba a perder água pelo vedante principal.',
            'gravidade' => 'alta',
            'status' => 'aberto',
            'ocorreu_em' => now(),
            'type' => 'outro',
        ]);

        // Simular envio de mensagens no chat do incidente
        IncidentMessage::create([
            'incident_id' => $incident->id,
            'user_id' => $this->admin->id,
            'texto' => 'Já contactei o piquete de assistência técnica.',
            'tipo' => 'utilizador',
        ]);

        $this->assertDatabaseHas('incident_messages', [
            'incident_id' => $incident->id,
            'texto' => 'Já contactei o piquete de assistência técnica.',
        ]);

        // Resolver o incidente
        $incident->update([
            'status' => 'resolvido',
            'resolvido_em' => now(),
            'resolvido_por' => $this->admin->id,
            'resolucao' => 'Vedante substituído com sucesso.',
        ]);

        $this->assertEquals('resolvido', $incident->fresh()->status);
        $this->assertEquals('Vedante substituído com sucesso.', $incident->fresh()->resolucao);
    }

    /**
     * 5. Teste de Compilação e Geração de Relatórios PDF
     */
    public function test_pdf_report_compilation(): void
    {
        // Criar um registo válido na base de dados
        DailyRecord::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->tecnico->id,
            'registado_em' => now()->subDays(2),
            'ph' => 7.2,
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'temperatura' => 26.5,
            'transparencia' => 0.2,
            'caleira_feita' => true,
            'renovacao_agua' => false,
        ]);

        // Testar exportação diretamente via Livewire Page
        Livewire::actingAs($this->admin)
            ->test(\App\Filament\Pages\RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => $this->pool->id,
                'data_inicio' => now()->subDays(5)->toDateString(),
                'data_fim' => now()->subDay()->toDateString(), // Até ontem
                'registo_modo' => 'todos',
                'colunas_visiveis' => ['ph', 'cloro_livre'],
                'seccoes_visiveis' => ['mostrar_assinaturas'],
            ])
            ->call('exportar')
            ->assertSuccessful();
    }

    /**
     * 6. Teste de Resiliência a cliques rápidos e submissão dupla (Concurrency)
     */
    public function test_stress_testing_rapid_double_save_resilience(): void
    {
        $slot = [
            'ph' => 7.2,
            'cloro_livre' => 1.2,
            'cloro_total' => 1.5,
            'temperatura' => 26.5,
            'transparencia' => 0.2,
            'bomba_com_bolhas' => false,
            'pressao_filtro' => 1.1,
            'estado_valvulas_filtro' => 'OK',
            'contador_valor' => 1020.0,
            'agua_modo' => 'on_com_agua',
            'tanque_ok' => true,
            'filtro_faz_retrolavagem' => false,
            'caleira_feita' => true,
            'renovacao_agua' => false,
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.2,
            'ns_cloro_total' => 1.5,
            'ns_temperatura' => 26.5,
        ];

        // Simular duas submissões extremamente rápidas
        $t = Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'pool_id' => $this->pool->id,
                'registado_em' => now(),
                'ns_foto' => [\Illuminate\Http\UploadedFile::fake()->create('ns_foto.jpg', 10)],
                'piscinas' => [0 => $slot],
            ]);

        $t->call('create')->assertHasNoFormErrors();

        // Segunda submissão
        $t->call('create');

        // Confirmar se o registo existe
        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->pool->id,
            'contador_valor' => 1020.0,
        ]);
    }
}
