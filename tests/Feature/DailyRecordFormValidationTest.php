<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyRecordFormValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;
    private Installation $leiria;
    private Pool $competicao;
    private Product $cloro;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
        ]);

        $this->cloro = Product::factory()->create([
            'name' => 'Cloro Ativo',
            'unidade' => 'kg',
            'categoria' => 'quimico',
        ]);
    }

    /**
     * Teste: Cloro total não pode ser menor que cloro livre.
     */
    public function test_ns_cloro_total_cannot_be_less_than_ns_cloro_livre(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 7.2,
                        'ns_cloro_livre' => 2.0,
                        'ns_cloro_total' => 1.5, // menor que ns_cloro_livre
                        'ns_temperatura' => 27.0,
                    ]
                ]
            ])
            ->call('create')
            ->assertHasFormErrors(["pools.{$this->competicao->id}.ns_cloro_total"]);
    }

    /**
     * Teste: Contador de água não pode retroceder em relação à última leitura da piscina.
     */
    public function test_contador_water_meter_cannot_go_backward(): void
    {
        // Criar registo anterior com contador = 150.5
        DailyRecord::create([
            'pool_id' => $this->competicao->id,
            'user_id' => $this->tecnico->id,
            'registado_em' => now()->subHours(2),
            'contador_valor' => 150.5,
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 27.0,
        ]);

        // Tentar preencher contador = 150.4 (retrocesso)
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'pools' => [
                    $this->competicao->id => [
                        'contador_valor' => 150.4, // menor que 150.5
                        'ns_ph' => 7.2,
                        'ns_cloro_livre' => 1.0,
                        'ns_cloro_total' => 1.2,
                        'ns_temperatura' => 27.0,
                    ]
                ]
            ])
            ->call('create')
            ->assertHasFormErrors(["pools.{$this->competicao->id}.contador_valor"]);
    }

    /**
     * Teste: Adição de químicos com quantidade superior ao stock disponível deve falhar.
     */
    public function test_chemical_addition_fails_if_quantity_exceeds_available_stock(): void
    {
        // Definir stock de 10.0 kg na instalação
        StockInstallation::create([
            'installation_id' => $this->leiria->id,
            'product_id' => $this->cloro->id,
            'quantity' => 10.0,
            'limite_minimo' => 2.0,
        ]);

        // Tentar adicionar 12.5 kg
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 7.2,
                        'ns_cloro_livre' => 1.0,
                        'ns_cloro_total' => 1.2,
                        'ns_temperatura' => 27.0,
                        'adicoes' => [
                            [
                                'product_id' => $this->cloro->id,
                                'quantity' => 12.5, // Maior que 10.0
                            ]
                        ]
                    ]
                ]
            ])
            ->call('create')
            ->assertHasFormErrors(["pools.{$this->competicao->id}.adicoes.0.quantity"]);
    }

    /**
     * Teste: Modal de confirmação recolhe e lista os problemas de inconformidade legal.
     */
    public function test_confirmation_modal_lists_non_compliant_parameters(): void
    {
        // pH fora dos limites (CN 14/DA: 6.9 - 8.0) -> pH 8.5
        $page = new CreateDailyRecord();
        $page->data = [
            'installation_id' => $this->leiria->id,
            'pools' => [
                $this->competicao->id => [
                    'ns_ph' => 8.5, // Fora do limite
                    'ns_cloro_livre' => 1.2,
                    'ns_temperatura' => 27.0,
                ]
            ]
        ];

        // Usar reflexão para aceder ao método privado conteudoModalConfirmacao
        $method = new \ReflectionMethod(CreateDailyRecord::class, 'getFormActions');
        $method->setAccessible(true);
        $actions = $method->invoke($page);

        // A primeira ação é 'create'
        $createAction = $actions[0];
        $view = $createAction->getModalContent();
        
        $viewData = $view->getData();
        
        $this->assertNotEmpty($viewData['problemas']);
        $this->assertStringContainsString('pH 8,5 — acima do máximo (8)', $viewData['problemas'][0]);
    }
}
