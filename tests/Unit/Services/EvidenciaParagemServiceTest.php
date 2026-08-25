<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Constants\TrabalhoParagem;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\SensorOutage;
use App\Models\SensorReading;
use App\Models\User;
use App\Services\EvidenciaParagemService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvidenciaParagemServiceTest extends TestCase
{
    use RefreshDatabase;

    private Pool $piscina;

    private PoolClosure $encerramento;

    private EvidenciaParagemService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->piscina = Pool::factory()->create([
            'orp_min' => 680,
            'orp_max' => 780,
            'temp_min' => 26.0,
            'temp_max' => 28.0,
        ]);

        $this->encerramento = PoolClosure::factory()->create([
            'pool_id' => $this->piscina->id,
            'inicio' => Carbon::parse('2026-08-01'),
            'fim' => Carbon::parse('2026-08-15'),
        ]);

        $this->service = app(EvidenciaParagemService::class);
    }

    public function test_candidatos_nao_escreve_nada_na_base_de_dados(): void
    {
        $contagemInicial = SensorReading::count();

        $candidatos = $this->service->candidatos($this->encerramento);

        $this->assertIsArray($candidatos);
        $this->assertEquals($contagemInicial, SensorReading::count());
    }

    public function test_limpeza_mecanica_e_legionella_devolvem_sempre_array_vazio(): void
    {
        // Criar dados de sensor aleatórios
        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-01',
            'lida_em' => Carbon::parse('2026-08-02 10:00:00'),
            'orp' => 750,
            'temperatura_agua' => 27.0,
        ]);

        $candidatos = $this->service->candidatos($this->encerramento);

        $this->assertEmpty($candidatos[TrabalhoParagem::LIMPEZA_TANQUE]);
        $this->assertEmpty($candidatos[TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO]);
        $this->assertEmpty($candidatos[TrabalhoParagem::LIMPEZA_CALEIRAS]);
        $this->assertEmpty($candidatos[TrabalhoParagem::MANUTENCAO_FILTROS]);
        $this->assertEmpty($candidatos[TrabalhoParagem::LIMPEZA_CIRCUITO]);
        $this->assertEmpty($candidatos[TrabalhoParagem::DESINFECAO_LEGIONELLA]);
    }

    public function test_deteta_pico_de_supercloracao_com_sucesso(): void
    {
        $baseDate = Carbon::parse('2026-08-05 10:00:00');

        // Criar linha de base normal
        for ($i = 0; $i < 10; $i++) {
            SensorReading::create([
                'pool_id' => $this->piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => $baseDate->copy()->addHours($i),
                'orp' => 710,
                'caudal_cloro' => 0,
            ]);
        }

        // Criar pico sustentado de supercloração
        $choqueDate = Carbon::parse('2026-08-06 00:00:00');
        for ($i = 0; $i < 8; $i++) {
            SensorReading::create([
                'pool_id' => $this->piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => $choqueDate->copy()->addHours($i),
                'orp' => 880,
                'caudal_cloro' => 120.0,
            ]);
        }

        // Queda para valores normais
        $normalDate = Carbon::parse('2026-08-06 10:00:00');
        for ($i = 0; $i < 5; $i++) {
            SensorReading::create([
                'pool_id' => $this->piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => $normalDate->copy()->addHours($i),
                'orp' => 720,
                'caudal_cloro' => 0,
            ]);
        }

        $candidatos = $this->service->candidatosPara($this->encerramento, TrabalhoParagem::SUPERCLORACAO);

        $this->assertCount(1, $candidatos);
        $this->assertEquals('alta', $candidatos[0]['confianca']);
        $this->assertEquals(880.0, $candidatos[0]['dados']['pico_orp']);
        $this->assertStringContainsString('ORP ≥', $candidatos[0]['criterio']);
    }

    public function test_serie_plana_de_orp_nao_gera_supercloracao(): void
    {
        $baseDate = Carbon::parse('2026-08-02 00:00:00');

        for ($i = 0; $i < 24; $i++) {
            SensorReading::create([
                'pool_id' => $this->piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => $baseDate->copy()->addHours($i),
                'orp' => 730,
                'caudal_cloro' => 0,
            ]);
        }

        $candidatos = $this->service->candidatosPara($this->encerramento, TrabalhoParagem::SUPERCLORACAO);

        $this->assertEmpty($candidatos);
    }

    public function test_deteta_rampa_de_aquecimento_sustentada(): void
    {
        $baseDate = Carbon::parse('2026-08-08 00:00:00');

        // Subida de 21.0 °C para 24.5 °C em 6 horas (passos de ~0.6 °C/hora)
        for ($i = 0; $i < 12; $i++) {
            $temp = $i <= 6 ? 21.0 + ($i * 0.6) : 24.6;
            SensorReading::create([
                'pool_id' => $this->piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => $baseDate->copy()->addHours($i),
                'temperatura_agua' => $temp,
                'temperatura_ar' => 20.0, // ar constante
            ]);
        }

        $candidatos = $this->service->candidatosPara($this->encerramento, TrabalhoParagem::ARRANQUE_AQUECIMENTO);

        $this->assertNotEmpty($candidatos);
        $this->assertEquals('alta', $candidatos[0]['confianca']);
        $this->assertStringContainsString('Subida térmica', $candidatos[0]['criterio']);
    }

    public function test_ondulacao_diurna_de_temperatura_nao_dispara_arranque_de_aquecimento(): void
    {
        $baseDate = Carbon::parse('2026-08-08 00:00:00');

        // Oscilação de apenas ±0.3 °C
        for ($i = 0; $i < 24; $i++) {
            $temp = 27.0 + (sin($i) * 0.3);
            SensorReading::create([
                'pool_id' => $this->piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => $baseDate->copy()->addHours($i),
                'temperatura_agua' => $temp,
                'temperatura_ar' => 22.0,
            ]);
        }

        $candidatos = $this->service->candidatosPara($this->encerramento, TrabalhoParagem::ARRANQUE_AQUECIMENTO);

        $this->assertEmpty($candidatos);
    }

    public function test_lacuna_coberta_por_sensor_outage_nao_e_reportada_como_tanque_vazio(): void
    {
        $t1 = Carbon::parse('2026-08-03 08:00:00');
        $t2 = Carbon::parse('2026-08-03 20:00:00'); // Lacuna de 12 horas

        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-01',
            'lida_em' => $t1,
            'orp' => 720,
        ]);

        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-01',
            'lida_em' => $t2,
            'orp' => 720,
        ]);

        $user = User::factory()->create();

        // Criar avaria cobrindo o intervalo
        SensorOutage::create([
            'pool_id' => $this->piscina->id,
            'user_id' => $user->id,
            'motivo' => 'avaria',
            'aberta_em' => Carbon::parse('2026-08-03 07:00:00'),
            'resolvida_em' => Carbon::parse('2026-08-03 21:00:00'),
            'observacoes' => 'Substituição de elétrodo',
        ]);

        $candidatos = $this->service->candidatosPara($this->encerramento, TrabalhoParagem::ESVAZIAMENTO_TANQUE);

        $this->assertEmpty($candidatos);
    }

    public function test_lacuna_sem_sensor_outage_e_reportada_como_candidato_a_tanque_vazio(): void
    {
        $t1 = Carbon::parse('2026-08-03 08:00:00');
        $t2 = Carbon::parse('2026-08-03 20:00:00'); // Lacuna de 12 horas sem avaria

        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-01',
            'lida_em' => $t1,
            'orp' => 720,
        ]);

        SensorReading::create([
            'pool_id' => $this->piscina->id,
            'hanna_device_id' => 'DEV-01',
            'lida_em' => $t2,
            'orp' => 720,
        ]);

        $candidatos = $this->service->candidatosPara($this->encerramento, TrabalhoParagem::ESVAZIAMENTO_TANQUE);

        $this->assertCount(1, $candidatos);
        $this->assertEquals('baixa', $candidatos[0]['confianca']);
        $this->assertEquals(12.0, $candidatos[0]['dados']['duracao_horas']);
    }
}
