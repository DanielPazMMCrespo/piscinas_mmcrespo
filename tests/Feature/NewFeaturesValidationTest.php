<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\Product;
use App\Models\TapAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NewFeaturesValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);
    }

    private function createTestEnvironment(): array
    {
        $installation = Installation::create([
            'name' => 'Leiria',
            'morada' => 'Rua Teste',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Lazer',
            'type' => 'leisure',
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'volume' => 600.00,
            'active' => true,
        ]);

        $product = Product::create([
            'name' => 'Cloro Granulado',
            'unidade' => 'kg',
            'active' => true,
        ]);

        $admin = User::factory()->create(['name' => 'Admin Daniel']);
        $admin->assignRole('admin');

        return [
            'installation' => $installation,
            'pool' => $pool,
            'product' => $product,
            'admin' => $admin,
        ];
    }

    public function test_quick_shortcuts_are_provided_in_esquema_page(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];

        $this->actingAs($admin);

        // Access the Esquema page via Livewire to inspect view data
        Livewire::test(\App\Filament\Pages\EsquemaPiscina::class)
            ->assertSet('poolId', $pool->id)
            ->assertViewHas('esquema', function (array $esquema) use ($pool) {
                $this->assertArrayHasKey('url_acoes_rapidas', $esquema);
                $this->assertStringContainsString('tipo=torneira', $esquema['url_acoes_rapidas']['torneira']);
                $this->assertStringContainsString('pool=' . $pool->id, $esquema['url_acoes_rapidas']['torneira']);
                return true;
            });
    }

    public function test_operational_action_creation_form_prefills_correctly(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];

        $this->actingAs($admin);

        // Access page with query parameters
        $url = \App\Filament\Resources\OperationalActionResource::getUrl('create', [
            'pool' => $pool->id,
            'tipo' => OperationalAction::TIPO_TORNEIRA,
        ]);

        // Simulating the request to test Filament Form defaults via Livewire
        Livewire::withQueryParams(['pool' => $pool->id, 'tipo' => OperationalAction::TIPO_TORNEIRA])
            ->test(\App\Filament\Resources\OperationalActionResource\Pages\CreateOperationalAction::class)
            ->assertFormSet([
                'pool_id' => $pool->id,
                'tipo' => OperationalAction::TIPO_TORNEIRA,
            ]);
    }

    public function test_tap_alert_closes_on_closed_tap_operational_action(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];

        $this->actingAs($admin);

        // Open the tap (creates TapAlert)
        $alert = TapAlert::create([
            'pool_id' => $pool->id,
            'opened_by' => $admin->id,
            'opened_at' => now(),
        ]);

        $this->assertNull($alert->resolved_at);

        // Record an operational action to close the tap
        OperationalAction::create([
            'pool_id' => $pool->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_TORNEIRA,
            'registado_em' => now(),
            'dados' => ['agua_modo' => 'off'],
        ]);

        $alert->refresh();
        $this->assertNotNull($alert->resolved_at);
        $this->assertEquals('acao_operacional', $alert->resolution);
    }

    public function test_daily_record_corrective_action_saves_to_record_additions_via_handle_creation(): void
    {
        $env = $this->createTestEnvironment();
        $admin = $env['admin'];
        $pool = $env['pool'];
        $product = $env['product'];

        $createPage = new \App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord();

        $data = [
            'installation_id' => $env['installation']->id,
            'user_id' => $admin->id,
            'registado_em' => now(),
            'pools' => [
                $pool->id => [
                    'ns_ph' => 7.20,
                    'ns_cloro_livre' => 1.50,
                    'ns_cloro_total' => 2.00,
                    'ns_temperatura' => 28.5,
                    'adicoes' => [
                        [
                            'product_id' => $product->id,
                            'quantity' => 1.5,
                            'acao_corretiva' => 'Ajuste pH e Cloro manual',
                        ]
                    ],
                ]
            ],
        ];

        $method = new \ReflectionMethod($createPage, 'handleRecordCreation');
        $method->setAccessible(true);
        $method->invoke($createPage, $data);

        $record = DailyRecord::where('pool_id', $pool->id)->first();
        $this->assertNotNull($record);

        $addition = $record->adicoes()->first();
        $this->assertNotNull($addition);
        $this->assertEquals('Ajuste pH e Cloro manual', $addition->acao_corretiva);
        $this->assertEquals($product->id, $addition->product_id);
    }
}
