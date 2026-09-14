<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DosingContainer;
use App\Models\HannaDevice;
use App\Models\Installation;
use App\Models\Pool;
use App\Services\HannaCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DosingPumpCalibrationTest extends TestCase
{
    use RefreshDatabase;

    private function createContainer(array $attrs = []): DosingContainer
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua Teste', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);

        return DosingContainer::create(array_merge([
            'pool_id' => $pool->id,
            'tipo' => DosingContainer::TIPO_CLORO,
            'capacidade_ml' => 25000,
            'restante_ml' => 25000,
            'alerta_percent' => 20,
        ], $attrs));
    }

    public function test_calcular_fator_returns_1_for_standard_bl132(): void
    {
        $container = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_STANDARD,
        ]);

        $this->assertSame(1.0, $container->obterFatorCalculado());
        $this->assertSame(1.0, DosingContainer::calcularFator(3.5, 100));
    }

    public function test_calcular_fator_for_hanna_bl10_2(): void
    {
        $c60 = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
            'bomba_potenciometro_percent' => 60,
        ]);
        // 10.8 * 0.60 = 6.48; 6.48 / 3.5 = 1.851428... -> round 1.851
        $this->assertSame(1.851, $c60->obterFatorCalculado());
        $this->assertSame(1.851, DosingContainer::calcularFator(10.8, 60));

        $c100 = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
            'bomba_potenciometro_percent' => 100,
        ]);
        // 10.8 * 1.0 = 10.8; 10.8 / 3.5 = 3.085714... -> round 3.086
        $this->assertSame(3.086, $c100->obterFatorCalculado());
        $this->assertSame(3.086, DosingContainer::calcularFator(10.8, 100));

        $c0 = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
            'bomba_potenciometro_percent' => 0,
        ]);
        $this->assertSame(1.0, $c0->obterFatorCalculado());
        $this->assertSame(1.0, DosingContainer::calcularFator(10.8, 0));
    }

    public function test_calcular_fator_for_custom_pump(): void
    {
        $custom = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_CUSTOM,
            'bomba_capacidade_max_lh' => 7.0,
            'bomba_potenciometro_percent' => 50,
        ]);
        // 7.0 * 0.50 = 3.5; 3.5 / 3.5 = 1.000
        $this->assertSame(1.000, $custom->obterFatorCalculado());
        $this->assertSame(1.000, DosingContainer::calcularFator(7.0, 50));

        $customNull = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_CUSTOM,
            'bomba_capacidade_max_lh' => null,
            'bomba_potenciometro_percent' => 50,
        ]);
        $this->assertSame(1.0, $customNull->obterFatorCalculado());
        $this->assertSame(1.0, DosingContainer::calcularFator(null, 50));
    }

    public function test_saving_container_automatically_updates_fator_correcao(): void
    {
        $container = $this->createContainer([
            'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
            'bomba_capacidade_max_lh' => 10.80,
            'bomba_potenciometro_percent' => 60,
        ]);

        $this->assertEquals(1.851, (float) $container->fresh()->fator_correcao);

        // Update potentiometer to 80%
        $container->update(['bomba_potenciometro_percent' => 80]);
        // 10.8 * 0.80 = 8.64; 8.64 / 3.5 = 2.46857... -> 2.469
        $this->assertEquals(2.469, (float) $container->fresh()->fator_correcao);
    }

    public function test_descricao_bomba_formatting(): void
    {
        $standard = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_STANDARD,
        ]);
        $this->assertSame('Padrão BL132 (3,5 L/h)', $standard->descricaoBomba());

        $bl10 = new DosingContainer([
            'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
            'bomba_capacidade_max_lh' => 10.80,
            'bomba_potenciometro_percent' => 60,
            'fator_correcao' => 1.851,
        ]);
        $this->assertSame('Hanna BL10-2 @ 60% (6,5 L/h · 1,85×)', $bl10->descricaoBomba());
    }

    public function test_recalcular_consumo_apos_reabastecimento_applies_fator_correcao(): void
    {
        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $container = $this->createContainer([
            'capacidade_ml' => 25000,
            'restante_ml' => 25000,
            'bomba_modelo' => DosingContainer::BOMBA_HANNA_BL10_2,
            'bomba_capacidade_max_lh' => 10.80,
            'bomba_potenciometro_percent' => 60,
            'fator_correcao' => 1.851,
            'reabastecido_em' => now()->subHours(5),
        ]);

        $device = HannaDevice::create([
            'hanna_device_id' => 'DEV-COMP',
            'name' => 'Sensor Competição',
            'pool_id' => $container->pool_id,
            'active' => true,
            'dose_sincronizada_ate' => now(),
        ]);

        $mockHanna = $this->mock(HannaCloudService::class);
        $mockHanna->shouldReceive('authenticate')->once();
        $mockHanna->shouldReceive('getHistoryReadings')
            ->with($device->hanna_device_id, \Mockery::any(), \Mockery::any())
            ->once()
            ->andReturn([
                ['dt' => now()->subHours(3)->toIso8601String(), 'dose_cloro_ml' => 600],
                ['dt' => now()->subHours(1)->toIso8601String(), 'dose_cloro_ml' => 400],
            ]);

        $container->recalcularConsumoAposReabastecimento();

        // Raw dose: (600 + 400) = 1000 mL * 1.851 factor = 1851 mL
        // Remaining: 25000 - 1851 = 23149 mL
        $this->assertEquals(23149.0, (float) $container->fresh()->restante_ml);
    }
}
