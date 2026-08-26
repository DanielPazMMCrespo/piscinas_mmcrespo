<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HannaDevice;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use App\Notifications\HannaSyncFalhouNotification;
use App\Services\CacheService;
use App\Services\HannaCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HannaSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(array $override = []): HannaDevice
    {
        $installation = Installation::create([
            'name' => 'Leiria',
            'morada' => 'Rua Teste',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);

        return HannaDevice::create(array_merge([
            'hanna_device_id' => 'DEV-001',
            'name' => 'Sensor Competição',
            'pool_id' => $pool->id,
            'active' => true,
        ], $override));
    }

    private function defaultReading(): array
    {
        return [
            'dt' => now()->toDateTimeString(),
            'ph' => 7.2,
            'orp' => 750.0,
            'temperatura_agua' => 26.5,
            'temperatura_ar' => 25.0,
            'caudal_ph' => 1.2,
            'caudal_cloro' => 0.8,
            'raw_parameters' => ['test' => true],
        ];
    }

    public function test_sync_stores_new_reading(): void
    {
        $this->createDevice();

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getLastReading')
            ->with('DEV-001')
            ->once()
            ->andReturn($this->defaultReading());

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')->assertSuccessful();

        $this->assertDatabaseHas('sensor_readings', [
            'hanna_device_id' => 'DEV-001',
            'ph' => '7.20',
        ]);
    }

    public function test_sync_ignores_duplicate_reading(): void
    {
        $device = $this->createDevice();
        $timestamp = now()->toDateTimeString();

        SensorReading::create([
            'pool_id' => $device->pool_id,
            'hanna_device_id' => 'DEV-001',
            'lida_em' => $timestamp,
            'ph' => 7.2,
            'orp' => 750.0,
            'temperatura_agua' => 26.5,
            'temperatura_ar' => 25.0,
        ]);

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getLastReading')
            ->with('DEV-001')
            ->once()
            ->andReturn(array_merge($this->defaultReading(), ['dt' => $timestamp]));

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')->assertSuccessful();

        $this->assertSame(1, SensorReading::count());
    }

    public function test_sync_fails_gracefully_on_auth_failure(): void
    {
        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')
            ->once()
            ->andThrow(new \RuntimeException('Auth failed'));

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'wrongpassword']);

        $this->artisan('hanna:sync')->assertFailed();
        $this->assertDatabaseCount('sensor_readings', 0);
    }

    /**
     * Reproduz a avaria que parou as 5 sondas: a Hanna Cloud manda o relógio
     * local do controlador com sufixo Z, e a leitura era lida como UTC — uma
     * hora no futuro no verão português — e rejeitada como implausível.
     */
    public function test_z_suffixed_local_timestamp_is_stored_not_rejected(): void
    {
        config(['app.timezone' => 'Europe/Lisbon']);
        Carbon::setTestNow(Carbon::parse('2026-08-26 15:25:00', 'Europe/Lisbon'));

        $this->createDevice();

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getLastReading')
            ->with('DEV-001')
            ->once()
            ->andReturn(array_merge($this->defaultReading(), [
                'dt' => '2026-08-26T15:12:00.000Z',
            ]));

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')->assertSuccessful();

        $this->assertDatabaseHas('sensor_readings', [
            'hanna_device_id' => 'DEV-001',
            'lida_em' => '2026-08-26 15:12:00',
        ]);

        Carbon::setTestNow();
    }

    /**
     * O painel do dashboard tem cache de 10 min e SensorReading::upsert() não
     * dispara eventos de model, logo nenhum observer invalidava o cache: a
     * leitura entrava na base e o dashboard continuava a mostrar o valor velho.
     *
     * Espiamos o CacheService em vez de ler o cache: a invalidação por padrão
     * wildcard só funciona nos drivers redis e database, e os testes correm
     * com o driver array.
     */
    public function test_new_reading_invalidates_the_dashboard_cache(): void
    {
        $this->createDevice();

        // Pool::saved() invalida o cache; espiar só depois do arranjo para
        // contar apenas o que o sync faz.
        $cache = $this->spy(CacheService::class);

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getLastReading')
            ->with('DEV-001')
            ->once()
            ->andReturn($this->defaultReading());

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')->assertSuccessful();

        $cache->shouldHaveReceived('invalidatePoolData')->once();
        $cache->shouldHaveReceived('invalidateAllAlerts')->once();
        $cache->shouldHaveReceived('invalidateGraphCache')->once();
    }

    private function createAdmin(): User
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_auth_failure_notifies_admins(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')
            ->once()
            ->andThrow(new \RuntimeException('invalidUsernameOrPassword'));

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'wrongpassword']);

        $this->artisan('hanna:sync')->assertFailed();

        Notification::assertSentTo($admin, HannaSyncFalhouNotification::class);
    }

    public function test_auth_failure_notifies_only_once_within_window(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')
            ->twice()
            ->andThrow(new \RuntimeException('invalidUsernameOrPassword'));

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'wrongpassword']);

        $this->artisan('hanna:sync')->assertFailed();
        $this->artisan('hanna:sync')->assertFailed();

        Notification::assertSentToTimes($admin, HannaSyncFalhouNotification::class, 1);
    }

    public function test_successful_auth_clears_notification_lock(): void
    {
        Cache::put('hanna:sync:auth_falha_notificada', now()->toDateTimeString(), now()->addHours(6));
        $this->createDevice();

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getLastReading')
            ->with('DEV-001')
            ->once()
            ->andReturn($this->defaultReading());

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')->assertSuccessful();

        $this->assertFalse(Cache::has('hanna:sync:auth_falha_notificada'));
    }

    public function test_sync_skips_device_on_api_error_without_crashing(): void
    {
        $this->createDevice();

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getLastReading')
            ->with('DEV-001')
            ->once()
            ->andThrow(new \RuntimeException('API error'));

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')->assertSuccessful();
        $this->assertDatabaseCount('sensor_readings', 0);
    }

    public function test_sync_warns_when_no_devices_configured(): void
    {
        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync')
            ->assertSuccessful()
            ->expectsOutputToContain('Nenhum dispositivo Hanna configurado');
    }

    public function test_sync_returns_failure_without_credentials(): void
    {
        config(['services.hanna.email' => '', 'services.hanna.password' => '']);

        $this->artisan('hanna:sync')->assertFailed();
    }

    public function test_discover_preserves_manually_disabled_device(): void
    {
        $device = $this->createDevice(['active' => false]);

        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getDevices')->once()->andReturn([
            ['DID' => 'DEV-001', 'name' => 'Sensor Competição', 'DM' => 'BL132'],
        ]);

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync --discover')->assertSuccessful();

        $this->assertFalse((bool) $device->fresh()->active);
    }

    public function test_discover_creates_new_device_as_active(): void
    {
        $mock = $this->mock(HannaCloudService::class);
        $mock->shouldReceive('authenticate')->once();
        $mock->shouldReceive('getDevices')->once()->andReturn([
            ['DID' => 'DEV-999', 'name' => 'Novo Sensor', 'DM' => 'BL132'],
        ]);

        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $this->artisan('hanna:sync --discover')->assertSuccessful();

        $this->assertDatabaseHas('hanna_devices', ['hanna_device_id' => 'DEV-999', 'active' => true]);
    }
}
