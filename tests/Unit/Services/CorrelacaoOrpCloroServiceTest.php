<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\DailyRecord;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\SensorReading;
use App\Models\User;
use App\Services\CorrelacaoOrpCloroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CorrelacaoOrpCloroServiceTest extends TestCase
{
    use RefreshDatabase;

    private CorrelacaoOrpCloroService $service;

    private Pool $piscina;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CorrelacaoOrpCloroService::class);
        $this->piscina = Pool::factory()->create();
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function medicao(string $momento, float $cloro, float $orp, ?float $ph = 7.4, int $desvioMinutos = 0): void
    {
        $quando = Carbon::parse($momento);

        DailyRecord::create([
            'pool_id' => $this->piscina->id,
            'user_id' => $this->user->id,
            'registado_em' => $quando,
            'hora_colheita' => $quando->format('H:i'),
            'cloro_livre' => $cloro,
            'ph' => $ph,
        ]);

        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-TESTE',
            'lida_em' => $quando->copy()->addMinutes($desvioMinutos),
            'orp' => $orp,
        ]);
    }

    public function test_emparelha_medicao_manual_com_a_leitura_de_sonda_mais_proxima(): void
    {
        $this->medicao('2026-08-10 10:00:00', 1.20, 700.0);

        // Leitura muito mais distante no tempo: nao deve ser a escolhida.
        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-TESTE',
            'lida_em' => Carbon::parse('2026-08-10 10:25:00'),
            'orp' => 999.0,
        ]);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(1, $r['n']);
        $this->assertSame(700.0, $r['pares'][0]['orp']);
    }

    public function test_ignora_medicao_sem_leitura_de_sonda_na_janela(): void
    {
        DailyRecord::create([
            'pool_id' => $this->piscina->id,
            'user_id' => $this->user->id,
            'registado_em' => Carbon::parse('2026-08-10 10:00:00'),
            'cloro_livre' => 1.20,
            'ph' => 7.4,
        ]);

        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-TESTE',
            'lida_em' => Carbon::parse('2026-08-10 14:00:00'),
            'orp' => 700.0,
        ]);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(0, $r['n']);
        $this->assertFalse($r['utilizavel']);
    }

    public function test_correlacao_forte_fica_utilizavel_e_estima_cloro(): void
    {
        // Relacao limpa: +100 mV por cada +1 mg/L.
        $this->medicao('2026-08-10 10:00:00', 0.50, 650.0);
        $this->medicao('2026-08-11 10:00:00', 1.00, 700.0);
        $this->medicao('2026-08-12 10:00:00', 1.50, 750.0);
        $this->medicao('2026-08-13 10:00:00', 2.00, 800.0);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(4, $r['n']);
        $this->assertTrue($r['utilizavel']);
        $this->assertEqualsWithDelta(1.0, (float) $r['r'], 0.001);
        $this->assertEqualsWithDelta(1.25, (float) $this->service->estimarCloro($r, 725.0), 0.01);
        $this->assertSame(650.0, $r['orp_min_par']);
        $this->assertSame(800.0, $r['orp_max_par']);
    }

    public function test_amostra_pequena_nao_e_utilizavel_e_nao_estima(): void
    {
        $this->medicao('2026-08-10 10:00:00', 0.50, 650.0);
        $this->medicao('2026-08-11 10:00:00', 1.00, 700.0);
        $this->medicao('2026-08-12 10:00:00', 1.50, 750.0);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(3, $r['n']);
        $this->assertFalse($r['utilizavel']);
        // Um numero sem amostra que o sustente e pior do que numero nenhum.
        $this->assertNull($this->service->estimarCloro($r, 725.0));
    }

    public function test_correlacao_fraca_nao_e_utilizavel(): void
    {
        // ORP a subir com o cloro a andar ao contrario: sem relacao utilizavel.
        $this->medicao('2026-08-10 10:00:00', 2.00, 650.0);
        $this->medicao('2026-08-11 10:00:00', 0.40, 700.0);
        $this->medicao('2026-08-12 10:00:00', 1.90, 750.0);
        $this->medicao('2026-08-13 10:00:00', 0.50, 800.0);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(4, $r['n']);
        $this->assertFalse($r['utilizavel']);
        $this->assertNull($this->service->estimarCloro($r, 725.0));
    }

    public function test_ignora_leitura_tirada_durante_lavagem_de_filtro(): void
    {
        $this->medicao('2026-08-10 10:00:00', 0.50, 650.0);
        $this->medicao('2026-08-11 10:00:00', 1.00, 700.0);

        // Leitura absurda tirada em plena lavagem: o ORP despenca porque a agua
        // que passa na sonda nao e a da piscina. Sem a exclusao, este par
        // sozinho destroi a correlacao.
        OperationalAction::create([
            'pool_id' => $this->piscina->id,
            'user_id' => $this->user->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => Carbon::parse('2026-08-12 09:55:00'),
        ]);
        $this->medicao('2026-08-12 10:00:00', 2.95, 297.0);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertSame(2, $r['n']);
        $this->assertSame([650.0, 700.0], array_column($r['pares'], 'orp'));
    }

    public function test_explica_que_o_problema_e_a_relacao_e_nao_o_numero_de_pares(): void
    {
        // Pares que chegam, relacao que nao existe.
        $this->medicao('2026-08-10 10:00:00', 2.00, 650.0);
        $this->medicao('2026-08-11 10:00:00', 0.40, 700.0);
        $this->medicao('2026-08-12 10:00:00', 1.90, 750.0);
        $this->medicao('2026-08-13 10:00:00', 0.50, 800.0);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertFalse($r['utilizavel']);
        // Dizer "amostra insuficiente" com 4+ pares seria mentira no relatorio.
        $this->assertStringNotContainsString('suficientes', (string) $r['motivo_nao_validada']);
        $this->assertStringContainsString('sem relação utilizável', (string) $r['motivo_nao_validada']);
    }

    public function test_explica_a_falta_de_pares_quando_a_amostra_e_pequena(): void
    {
        $this->medicao('2026-08-10 10:00:00', 0.50, 650.0);
        $this->medicao('2026-08-11 10:00:00', 1.00, 700.0);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        $this->assertStringContainsString('pares suficientes', (string) $r['motivo_nao_validada']);
    }

    public function test_regista_a_gama_de_ph_dos_pares(): void
    {
        $this->medicao('2026-08-10 10:00:00', 0.50, 650.0, 7.10);
        $this->medicao('2026-08-11 10:00:00', 1.00, 700.0, 7.40);
        $this->medicao('2026-08-12 10:00:00', 1.50, 750.0, 7.60);
        $this->medicao('2026-08-13 10:00:00', 2.00, 800.0, 7.30);

        $r = $this->service->analisar($this->piscina, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

        // A correlacao so vale dentro da gama de pH em que foi construida.
        $this->assertSame(7.10, $r['ph_min']);
        $this->assertSame(7.60, $r['ph_max']);
    }
}
