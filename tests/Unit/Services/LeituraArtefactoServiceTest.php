<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use App\Services\LeituraArtefactoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeituraArtefactoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Pool $pool;

    private LeituraArtefactoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $installation = Installation::factory()->create();
        $this->pool = Pool::factory()->create(['installation_id' => $installation->id]);
        $this->service = app(LeituraArtefactoService::class);
    }

    public function test_no_artifacts_when_no_records(): void
    {
        $start = Carbon::parse('2026-07-19 10:00:00');
        $end = Carbon::parse('2026-07-19 12:00:00');

        $janelas = $this->service->janelas($this->pool->id, $start, $end);

        $this->assertEmpty($janelas);
    }

    public function test_creates_artifact_window_for_filter_wash_action(): void
    {
        $start = Carbon::parse('2026-07-19 10:00:00');
        $end = Carbon::parse('2026-07-19 12:00:00');
        $actionTime = Carbon::parse('2026-07-19 11:00:00');

        $user = User::factory()->create();

        OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => $actionTime,
            'dados' => ['duracao_min' => 15],
        ]);

        $janelas = $this->service->janelas($this->pool->id, $start, $end);

        $this->assertCount(1, $janelas);
        $this->assertEquals($actionTime, $janelas[0]['inicio']);
        // 15 min wash + 10 min stabilization
        $this->assertEquals($actionTime->copy()->addMinutes(25), $janelas[0]['fim']);
        $this->assertEquals('Lavagem de filtro', $janelas[0]['motivo']);
    }

    public function test_creates_artifact_window_for_daily_record_wash(): void
    {
        $start = Carbon::parse('2026-07-19 10:00:00');
        $end = Carbon::parse('2026-07-19 12:00:00');
        $recordTime = Carbon::parse('2026-07-19 11:00:00');

        $user = User::factory()->create();

        DailyRecord::factory()->create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'registado_em' => $recordTime,
            'filtro_faz_retrolavagem' => true,
        ]);

        $janelas = $this->service->janelas($this->pool->id, $start, $end);

        $this->assertCount(1, $janelas);
        $this->assertEquals($recordTime, $janelas[0]['inicio']);
        // Default 20 min + 10 min stabilization
        $this->assertEquals($recordTime->copy()->addMinutes(30), $janelas[0]['fim']);
        $this->assertEquals('Lavagem de filtro', $janelas[0]['motivo']);
    }

    public function test_creates_artifact_window_for_stopped_pump_via_operational_actions(): void
    {
        $start = Carbon::parse('2026-07-19 10:00:00');
        $end = Carbon::parse('2026-07-19 15:00:00');

        $stopTime = Carbon::parse('2026-07-19 11:00:00');
        $startTime = Carbon::parse('2026-07-19 13:00:00');

        $user = User::factory()->create();

        // Stop pump
        OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
            'registado_em' => $stopTime,
            'dados' => ['bomba_ferrada' => false],
        ]);

        // Start pump
        OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
            'registado_em' => $startTime,
            'dados' => ['bomba_ferrada' => true],
        ]);

        $janelas = $this->service->janelas($this->pool->id, $start, $end);

        $this->assertCount(1, $janelas);
        $this->assertEquals($stopTime, $janelas[0]['inicio']);
        $this->assertEquals($startTime, $janelas[0]['fim']);
        $this->assertEquals('Bomba parada', $janelas[0]['motivo']);
    }

    public function test_pump_stopped_until_end_of_period(): void
    {
        $start = Carbon::parse('2026-07-19 10:00:00');
        $end = Carbon::parse('2026-07-19 15:00:00');

        $stopTime = Carbon::parse('2026-07-19 11:00:00');

        $user = User::factory()->create();

        // Stop pump (no start afterwards)
        OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
            'registado_em' => $stopTime,
            'dados' => ['bomba_ferrada' => false],
        ]);

        $janelas = $this->service->janelas($this->pool->id, $start, $end);

        $this->assertCount(1, $janelas);
        $this->assertEquals($stopTime, $janelas[0]['inicio']);
        $this->assertEquals($end, $janelas[0]['fim']);
        $this->assertEquals('Bomba parada', $janelas[0]['motivo']);
    }

    public function test_clips_windows_to_requested_period(): void
    {
        $start = Carbon::parse('2026-07-19 10:00:00');
        $end = Carbon::parse('2026-07-19 12:00:00');

        $stopTime = Carbon::parse('2026-07-19 09:00:00');
        $startTime = Carbon::parse('2026-07-19 13:00:00');

        $user = User::factory()->create();

        // Stop pump before start of period
        OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
            'registado_em' => $stopTime,
            'dados' => ['bomba_ferrada' => false],
        ]);

        // Start pump after end of period
        OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'tipo' => OperationalAction::TIPO_BOMBA,
            'registado_em' => $startTime,
            'dados' => ['bomba_ferrada' => true],
        ]);

        $janelas = $this->service->janelas($this->pool->id, $start, $end);

        $this->assertCount(1, $janelas);
        $this->assertEquals($start, $janelas[0]['inicio']);
        $this->assertEquals($end, $janelas[0]['fim']);
        $this->assertEquals('Bomba parada', $janelas[0]['motivo']);
    }

    public function test_motivo_em_returns_correct_motive(): void
    {
        $actionTime = Carbon::parse('2026-07-19 11:00:00');

        $user = User::factory()->create();

        OperationalAction::create([
            'pool_id' => $this->pool->id,
            'user_id' => $user->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => $actionTime,
            'dados' => ['duracao_min' => 15],
        ]);

        // At 11:10, it's during the wash
        $checkTime = Carbon::parse('2026-07-19 11:10:00');
        $this->assertEquals('Lavagem de filtro', $this->service->motivoEm($this->pool->id, $checkTime));

        // At 11:30, it's outside the window (15m + 10m = 25m -> ends at 11:25)
        $checkTimeOutside = Carbon::parse('2026-07-19 11:30:00');
        $this->assertNull($this->service->motivoEm($this->pool->id, $checkTimeOutside));
    }
}
