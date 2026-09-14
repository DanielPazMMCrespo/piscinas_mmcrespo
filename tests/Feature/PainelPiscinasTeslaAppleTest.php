<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Widgets\PainelPiscinasWidget;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PainelPiscinasTeslaAppleTest extends TestCase
{
    use RefreshDatabase;

    private Pool $piscina;
    private User $admin;
    private User $nadador;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR, 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole(UserRole::NADADOR_SALVADOR);

        $instalacao = Installation::factory()->create(['active' => true]);
        $this->piscina = Pool::factory()->create(['installation_id' => $instalacao->id, 'active' => true]);

        Cache::flush();
    }

    public function test_calculate_range_gauge_mathematics_and_sweet_spot(): void
    {
        // pH test: scale 6.5 to 8.5, target 6.9 to 8.0, sweet spot 7.2 to 7.6, value 7.4
        $gauge = PainelPiscinasWidget::calculateRangeGauge(7.4, 6.5, 8.5, 6.9, 8.0, 7.2, 7.6);

        $this->assertNotNull($gauge);
        $this->assertSame(7.4, $gauge['value']);
        // 7.4 on [6.5, 8.5]: (7.4 - 6.5) / 2.0 = 0.9 / 2.0 = 45.0%
        $this->assertEquals(45.0, $gauge['percent']);
        // Target 6.9 on [6.5, 8.5]: 0.4 / 2.0 = 20.0%
        $this->assertEquals(20.0, $gauge['target_start_percent']);
        // Target width: (8.0 - 6.9) / 2.0 = 1.1 / 2.0 = 55.0%
        $this->assertEquals(55.0, $gauge['target_width_percent']);
        $this->assertSame('ok', $gauge['status']);
        $this->assertFalse($gauge['is_clamped_low']);
        $this->assertFalse($gauge['is_clamped_high']);

        $this->assertNotNull($gauge['sweet_band']);
        // Sweet start: (7.2 - 6.5) / 2.0 = 0.7 / 2.0 = 35.0%
        $this->assertEquals(35.0, $gauge['sweet_band']['start_percent']);
        // Sweet width: (7.6 - 7.2) / 2.0 = 0.4 / 2.0 = 20.0%
        $this->assertEquals(20.0, $gauge['sweet_band']['width_percent']);
    }

    public function test_calculate_range_gauge_clamps_and_detects_alert_status(): void
    {
        // Value 9.0 on scale 6.5 to 8.5 (above scale max and target max)
        $gaugeHigh = PainelPiscinasWidget::calculateRangeGauge(9.0, 6.5, 8.5, 6.9, 8.0);
        $this->assertNotNull($gaugeHigh);
        $this->assertEquals(100.0, $gaugeHigh['percent']);
        $this->assertTrue($gaugeHigh['is_clamped_high']);
        $this->assertSame('high', $gaugeHigh['status']);

        // Value 6.0 on scale 6.5 to 8.5 (below scale min and target min)
        $gaugeLow = PainelPiscinasWidget::calculateRangeGauge(6.0, 6.5, 8.5, 6.9, 8.0);
        $this->assertNotNull($gaugeLow);
        $this->assertEquals(0.0, $gaugeLow['percent']);
        $this->assertTrue($gaugeLow['is_clamped_low']);
        $this->assertSame('low', $gaugeLow['status']);

        // Null value returns null
        $gaugeNull = PainelPiscinasWidget::calculateRangeGauge(null, 6.5, 8.5, 6.9, 8.0);
        $this->assertNull($gaugeNull);
    }

    public function test_build_pool_data_populates_tesla_gauges_for_holy_trinity(): void
    {
        DailyRecord::factory()->create([
            'pool_id' => $this->piscina->id,
            'user_id' => $this->admin->id,
            'registado_em' => now(),
            'ph' => 7.35,
            'cloro_livre' => 1.10,
            'temperatura' => 28.0,
        ]);

        $this->actingAs($this->admin);

        $widget = new PainelPiscinasWidget;
        $method = new \ReflectionMethod($widget, 'buildPoolData');
        $data = $method->invoke($widget);

        $item = collect($data['piscinas'])->firstWhere(fn (array $i) => $i['piscina']->id === $this->piscina->id);
        $this->assertNotNull($item);

        // Check pH gauge
        $this->assertNotNull($item['metricas4']['ph']['gauge']);
        $this->assertSame(7.35, $item['metricas4']['ph']['gauge']['value']);
        $this->assertSame('ok', $item['metricas4']['ph']['gauge']['status']);

        // Check Cloro Livre gauge (compliant within 0.5 - 1.2 mg/L for pH 7.35)
        $this->assertNotNull($item['metricas4']['livre']['gauge']);
        $this->assertSame(1.10, $item['metricas4']['livre']['gauge']['value']);
        $this->assertSame('ok', $item['metricas4']['livre']['gauge']['status']);

        // Secondary metrics have null gauge
        $this->assertNull($item['metricas4']['combinado']['gauge']);
        $this->assertNull($item['metricas4']['temp']['gauge']);
        $this->assertNull($item['metricas4']['turbidez']['gauge']);
    }

    public function test_authorization_and_pool_attribution_in_attach_quick_actions(): void
    {
        $this->actingAs($this->admin);
        $widget = new PainelPiscinasWidget;
        $method = new \ReflectionMethod($widget, 'getViewData');
        $viewData = $method->invoke($widget);

        $this->assertFalse($viewData['isNS']);
        $poolItem = collect($viewData['piscinas'])->firstWhere(fn (array $i) => $i['piscina']->id === $this->piscina->id);
        $this->assertTrue($poolItem['pode_registar']);

        // Test Nadador Salvador without pool assigned
        $this->actingAs($this->nadador);
        $nsWidget = new PainelPiscinasWidget;
        $nsMethod = new \ReflectionMethod($nsWidget, 'getViewData');
        $nsViewData = $nsMethod->invoke($nsWidget);

        $this->assertTrue($nsViewData['isNS']);
    }

    public function test_widget_renders_successfully(): void
    {
        $this->actingAs($this->admin);

        \Livewire\Livewire::test(PainelPiscinasWidget::class)
            ->assertSuccessful();
    }

    public function test_dashboard_page_loads_successfully(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get('/admin');
        $response->assertSuccessful();
    }
}
