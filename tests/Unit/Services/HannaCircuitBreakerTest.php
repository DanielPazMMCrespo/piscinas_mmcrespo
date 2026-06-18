<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\HannaCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HannaCircuitBreakerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Limpa o cache antes de cada teste
        Cache::flush();
    }

    public function test_initial_state_is_closed(): void
    {
        $state = HannaCircuitBreaker::state();

        $this->assertSame(HannaCircuitBreaker::STATE_CLOSED, $state);
    }

    public function test_allow_returns_true_when_closed(): void
    {
        Cache::put('hanna:circuit:state', HannaCircuitBreaker::STATE_CLOSED, 600);

        $this->assertTrue(HannaCircuitBreaker::allow());
    }

    public function test_record_failure_increments_counter(): void
    {
        HannaCircuitBreaker::recordFailure();
        HannaCircuitBreaker::recordFailure();

        $failures = Cache::get('hanna:circuit:failures', []);

        $this->assertCount(2, $failures);
    }

    public function test_circuit_opens_after_threshold(): void
    {
        // 5 falhas trigger o open
        for ($i = 0; $i < 5; $i++) {
            HannaCircuitBreaker::recordFailure();
        }

        $state = HannaCircuitBreaker::state();

        $this->assertSame(HannaCircuitBreaker::STATE_OPEN, $state);
    }

    public function test_allow_returns_false_when_open(): void
    {
        for ($i = 0; $i < 5; $i++) {
            HannaCircuitBreaker::recordFailure();
        }

        $this->assertFalse(HannaCircuitBreaker::allow());
    }

    public function test_record_success_closes_from_half_open(): void
    {
        // Força estado half-open
        Cache::put('hanna:circuit:state', HannaCircuitBreaker::STATE_HALF_OPEN, 120);

        HannaCircuitBreaker::recordSuccess();

        $state = HannaCircuitBreaker::state();

        $this->assertSame(HannaCircuitBreaker::STATE_CLOSED, $state);
    }

    public function test_record_success_clears_failures(): void
    {
        Cache::put('hanna:circuit:failures', [now()->timestamp], 600);

        HannaCircuitBreaker::recordSuccess();

        $failures = Cache::get('hanna:circuit:failures');

        $this->assertNull($failures);
    }

    public function test_failures_outside_window_are_ignored(): void
    {
        // Adiciona uma falha com timestamp antigo (>5 min)
        $oldFailure = now()->subMinutes(10)->timestamp;
        Cache::put('hanna:circuit:failures', [$oldFailure], 600);

        HannaCircuitBreaker::recordFailure();

        // Deveria haver apenas 1 falha (a nova), pois a antiga foi filtrada
        $failures = Cache::get('hanna:circuit:failures', []);

        $this->assertCount(1, $failures);
    }
}
