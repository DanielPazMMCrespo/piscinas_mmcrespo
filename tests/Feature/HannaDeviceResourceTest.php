<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\HannaDeviceResource;
use App\Models\HannaDevice;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HannaDeviceResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tecnico;

    private Pool $pool;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->installation = Installation::create([
            'name' => 'Leiria',
            'morada' => 'Rua Teste',
            'active' => true,
        ]);

        $this->pool = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);

        // Seed a fresh sensor reading to prevent the EnsureHannaReadingsAreFresh middleware from triggering sync jobs
        SensorReading::create([
            'pool_id' => $this->pool->id,
            'hanna_device_id' => 'DEV-123',
            'lida_em' => now(),
            'ph' => 7.2,
            'orp' => 750,
            'temperatura_agua' => 26.0,
        ]);
    }

    public function test_only_admin_can_access_hanna_devices(): void
    {
        // Admin has access
        $this->actingAs($this->admin);
        $this->get(HannaDeviceResource::getUrl())->assertSuccessful();

        // Técnico does not have access
        $this->actingAs($this->tecnico);
        $this->get(HannaDeviceResource::getUrl())->assertForbidden();
    }

    public function test_list_hanna_devices_renders_correctly(): void
    {
        $this->actingAs($this->admin);

        $device = HannaDevice::create([
            'hanna_device_id' => 'DEV-123',
            'name' => 'Sensor Principal',
            'pool_id' => $this->pool->id,
            'active' => true,
            'raw_info' => [],
        ]);

        Livewire::test(HannaDeviceResource\Pages\ListHannaDevices::class)
            ->assertCanSeeTableRecords([$device])
            ->assertTableColumnExists('hanna_device_id')
            ->assertTableColumnExists('name')
            ->assertTableColumnExists('piscina.name');
    }

    public function test_sync_now_action_success(): void
    {
        $this->actingAs($this->admin);

        // Mock the Artisan command/service
        Artisan::shouldReceive('call')
            ->with('hanna:sync')
            ->once()
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->andReturn('Sync completed successfully.');

        Livewire::test(HannaDeviceResource\Pages\ListHannaDevices::class)
            ->mountTableAction('sync_now')
            ->assertHasNoTableActionErrors()
            ->assertRedirect();
    }

    public function test_sync_now_action_failure(): void
    {
        $this->actingAs($this->admin);

        Artisan::shouldReceive('call')
            ->with('hanna:sync')
            ->once()
            ->andReturn(1);

        Artisan::shouldReceive('output')
            ->andReturn('Credentials missing.');

        Livewire::test(HannaDeviceResource\Pages\ListHannaDevices::class)
            ->mountTableAction('sync_now')
            ->assertHasNoTableActionErrors();
    }

    public function test_discover_action_success(): void
    {
        $this->actingAs($this->admin);

        Artisan::shouldReceive('call')
            ->with('hanna:sync', ['--discover' => true])
            ->once()
            ->andReturn(0);

        Artisan::shouldReceive('output')
            ->andReturn('1 devices discovered.');

        Livewire::test(HannaDeviceResource\Pages\ListHannaDevices::class)
            ->callTableAction('discover')
            ->assertHasNoTableActionErrors();
    }

    public function test_discover_action_failure(): void
    {
        $this->actingAs($this->admin);

        Artisan::shouldReceive('call')
            ->with('hanna:sync', ['--discover' => true])
            ->once()
            ->andReturn(1);

        Artisan::shouldReceive('output')
            ->andReturn('Auth failed.');

        Livewire::test(HannaDeviceResource\Pages\ListHannaDevices::class)
            ->callTableAction('discover')
            ->assertHasNoTableActionErrors();
    }

    public function test_ver_detalhes_action(): void
    {
        $this->actingAs($this->admin);

        $device = HannaDevice::create([
            'hanna_device_id' => 'DEV-123',
            'name' => 'Sensor Principal',
            'pool_id' => $this->pool->id,
            'active' => true,
            'raw_info' => [
                'DM' => 'BL132',
                'reportedSettings' => [
                    'SY' => 'Hanna,BL132,1.0,2.0,SER-12345',
                ],
            ],
        ]);

        Livewire::test(HannaDeviceResource\Pages\ListHannaDevices::class)
            ->callTableAction('view', $device)
            ->assertHasNoTableActionErrors();
    }

    public function test_crud_hanna_device(): void
    {
        $this->actingAs($this->admin);

        // Test Creation
        Livewire::test(HannaDeviceResource\Pages\CreateHannaDevice::class)
            ->fillForm([
                'hanna_device_id' => 'DEV-456',
                'name' => 'Sensor Secundário',
                'pool_id' => $this->pool->id,
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('hanna_devices', [
            'hanna_device_id' => 'DEV-456',
            'name' => 'Sensor Secundário',
            'pool_id' => $this->pool->id,
            'active' => 1,
        ]);

        $device = HannaDevice::where('hanna_device_id', 'DEV-456')->first();

        // Test Edit
        Livewire::test(HannaDeviceResource\Pages\EditHannaDevice::class, [
            'record' => $device->getKey(),
        ])
            ->fillForm([
                'name' => 'Sensor Secundário Editado',
                'active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('hanna_devices', [
            'hanna_device_id' => 'DEV-456',
            'name' => 'Sensor Secundário Editado',
            'active' => 0,
        ]);

        // Test Delete
        Livewire::test(HannaDeviceResource\Pages\EditHannaDevice::class, [
            'record' => $device->getKey(),
        ])
            ->callAction('delete');

        $this->assertDatabaseMissing('hanna_devices', [
            'hanna_device_id' => 'DEV-456',
        ]);
    }
}
