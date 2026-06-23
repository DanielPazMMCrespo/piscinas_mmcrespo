<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureHannaReadingsAreFresh;
use App\Jobs\ProcessHannaSync;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EnsureHannaFreshTest extends TestCase
{
    use RefreshDatabase;

    private function handle(string $method = 'GET'): void
    {
        $request = Request::create('/admin', $method);
        $middleware = new EnsureHannaReadingsAreFresh();
        $middleware->handle($request, fn ($r) => response('ok'));

        // afterResponse() defers dispatch via app()->terminating().
        // Calling terminate() here simulates the end-of-response lifecycle.
        app()->terminate();
    }

    private function pool(): Pool
    {
        $installation = Installation::create(['name' => 'L', 'morada' => 'R', 'active' => true]);

        return Pool::create([
            'installation_id' => $installation->id,
            'name' => 'P', 'type' => 'competition',
            'temp_min' => 26.0, 'temp_max' => 27.0, 'volume' => 900.0, 'active' => true,
        ]);
    }

    public function test_dispatches_job_when_no_readings_exist(): void
    {
        Bus::fake();

        $this->handle();

        Bus::assertDispatched(ProcessHannaSync::class);
    }

    public function test_does_not_dispatch_job_when_reading_is_fresh(): void
    {
        Bus::fake();

        SensorReading::create([
            'pool_id' => $this->pool()->id,
            'hanna_device_id' => 'DEV-001',
            'lida_em' => now()->subMinutes(10),
        ]);

        $this->handle();

        Bus::assertNotDispatched(ProcessHannaSync::class);
    }

    public function test_does_not_dispatch_on_non_get_requests(): void
    {
        Bus::fake();

        $this->handle('POST');

        Bus::assertNotDispatched(ProcessHannaSync::class);
    }

    public function test_reading_exactly_at_30_minutes_is_not_stale(): void
    {
        Bus::fake();

        SensorReading::create([
            'pool_id' => $this->pool()->id,
            'hanna_device_id' => 'DEV-001',
            'lida_em' => now()->subMinutes(30),
        ]);

        $this->handle();

        Bus::assertNotDispatched(ProcessHannaSync::class);
    }

}
