<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HannaDevice;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Services\HannaCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HannaSyncTest extends TestCase
{
    use RefreshDatabase;

    private function createDevice(array $override = []): HannaDevice
    {
        $installation = Installation::create([
            'name'    => 'Leiria',
            'morada'  => 'Rua Teste',
            'active'  => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name'            => 'Competição',
            'type'            => 'competition',
            'temp_min'        => 26.0,
            'temp_max'        => 27.0,
            'volume'          => 900.00,
            'active'          => true,
        ]);

        return HannaDevice::create(array_merge([
            'hanna_device_id' => 'DEV-001',
            'name'            => 'Sensor Competição',
            'pool_id'         => $pool->id,
            'active'          => true,
        ], $override));
    }

    private function defaultReading(): array
    {
        return [
            'dt'               => now()->toDateTimeString(),
            'ph'               => 7.2,
            'orp'              => 750.0,
            'temperatura_agua' => 26.5,
            'temperatura_ar'   => 25.0,
            'caudal_ph'        => 1.2,
            'caudal_cloro'     => 0.8,
            'raw_parameters'   => ['test' => true],
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
            'ph'              => '7.20',
        ]);
    }

    public function test_sync_ignores_duplicate_reading(): void
    {
        $device = $this->createDevice();
        $timestamp = now()->toDateTimeString();

        SensorReading::create([
            'pool_id'          => $device->pool_id,
            'hanna_device_id'  => 'DEV-001',
            'lida_em'          => $timestamp,
            'ph'               => 7.2,
            'orp'              => 750.0,
            'temperatura_agua' => 26.5,
            'temperatura_ar'   => 25.0,
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
}
