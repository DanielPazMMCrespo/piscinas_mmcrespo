<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\EnsureHannaReadingsAreFresh;
use App\Jobs\ProcessHannaSync;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnsureHannaFreshTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Todos os testes agem como utilizador autenticado por omissão — o
     * middleware agora exige auth()->check(), coberto à parte em
     * test_does_not_dispatch_for_unauthenticated_request().
     */
    private function handle(Request $request, bool $authenticated = true): void
    {
        if ($authenticated) {
            $this->actingAs(User::factory()->create());
        }

        $middleware = app(EnsureHannaReadingsAreFresh::class);
        $middleware->handle($request, fn ($r) => response('ok'));

        // afterResponse() registers a terminating callback on the Application.
        // trigger it here to simulate the end-of-request lifecycle.
        app()->terminate();
    }

    private function createReading(int $minutesAgo): SensorReading
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

        return SensorReading::create([
            'pool_id' => $pool->id,
            'hanna_device_id' => 'DEV-001',
            'lida_em' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function test_dispatches_job_when_no_readings_exist(): void
    {
        Queue::fake();

        $this->handle(Request::create('/admin', 'GET'));

        Queue::assertPushed(ProcessHannaSync::class);
    }

    public function test_dispatches_job_when_reading_is_stale(): void
    {
        Queue::fake();

        $this->createReading(31);

        $this->handle(Request::create('/admin', 'GET'));

        Queue::assertPushed(ProcessHannaSync::class);
    }

    public function test_does_not_dispatch_job_when_reading_is_fresh(): void
    {
        Queue::fake();

        $this->createReading(10);

        $this->handle(Request::create('/admin', 'GET'));

        Queue::assertNotPushed(ProcessHannaSync::class);
    }

    public function test_does_not_dispatch_on_non_get_requests(): void
    {
        Queue::fake();

        $this->handle(Request::create('/admin/registos-diarios', 'POST'));

        Queue::assertNotPushed(ProcessHannaSync::class);
    }

    public function test_reading_exactly_at_30_minutes_is_not_stale(): void
    {
        Queue::fake();

        $this->createReading(30);

        $this->handle(Request::create('/admin', 'GET'));

        Queue::assertNotPushed(ProcessHannaSync::class);
    }

    public function test_does_not_dispatch_for_unauthenticated_request(): void
    {
        Queue::fake();

        // Sem readings (stale) e sem sessão autenticada: um flood de GETs
        // anónimos não pode continuar a encher a fila.
        $this->handle(Request::create('/', 'GET'), authenticated: false);

        Queue::assertNotPushed(ProcessHannaSync::class);
    }

    public function test_dispatches_only_once_within_the_cooldown_window(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $middleware = app(EnsureHannaReadingsAreFresh::class);

        // Três pedidos "separados" sem afterResponse()/terminate() entre eles —
        // isso simula corretamente 3 requests reais (cada um com o seu próprio
        // ciclo de vida), ao contrário de chamar terminate() 3 vezes no mesmo
        // processo de teste, que replay todos os callbacks já acumulados.
        $middleware->handle(Request::create('/admin', 'GET'), fn ($r) => response('ok'));
        $middleware->handle(Request::create('/admin', 'GET'), fn ($r) => response('ok'));
        $middleware->handle(Request::create('/admin', 'GET'), fn ($r) => response('ok'));

        app()->terminate();

        Queue::assertPushed(ProcessHannaSync::class, 1);
    }
}
