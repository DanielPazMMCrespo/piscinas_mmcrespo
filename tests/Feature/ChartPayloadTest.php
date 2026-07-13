<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\CloroPhChartWidget;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChartPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'nadador_salvador', 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->syncRoles(['admin']);
        return $u;
    }

    private function pool(string $name, Installation $inst): Pool
    {
        return Pool::create([
            'installation_id' => $inst->id,
            'name' => $name,
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.00,
            'active' => true,
        ]);
    }

    private function record(Pool $pool, array $attrs): DailyRecord
    {
        return DailyRecord::create(array_merge([
            'pool_id' => $pool->id,
            'user_id' => $this->admin()->id,
            'registado_em' => Carbon::now()->subDay(),
            'agua_modo' => 'off',
        ], $attrs));
    }

    public function test_dual_mode_returns_two_series_with_axis_and_band(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);
        $this->record($pool, ['ph' => 7.4, 'cloro_livre' => 1.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'dual')
            ->set('leftMetric', 'ph')
            ->set('rightMetric', 'cloro_livre')
            ->set('poolSelecionada', (string) $pool->id);

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('dual', $payload['mode']);
        $this->assertCount(2, $payload['series']);
        $this->assertSame('left', $payload['series'][0]['axis']);
        $this->assertSame('right', $payload['series'][1]['axis']);
        $this->assertSame('ph', $payload['series'][0]['key']);
        $this->assertNotNull($payload['series'][0]['banda']);
        $this->assertCount(1, $payload['series'][0]['data']);
        $this->assertEqualsWithDelta(7.4, $payload['series'][0]['data'][0]['y'], 0.001);
    }

    public function test_multi_metrica_filters_invalid_keys_and_carries_ymin_ymax(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);
        $this->record($pool, ['ph' => 7.4, 'cloro_livre' => 1.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'multi-metrica')
            ->set('selectedMetrics', ['ph', 'cloro_livre', 'inexistente'])
            ->set('poolSelecionada', (string) $pool->id);

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('multi-metrica', $payload['mode']);
        $this->assertCount(2, $payload['series']);
        $keys = array_column($payload['series'], 'key');
        $this->assertSame(['ph', 'cloro_livre'], $keys);
        $this->assertArrayHasKey('yMin', $payload['series'][0]);
        $this->assertArrayHasKey('yMax', $payload['series'][0]);
    }

    public function test_multi_piscina_one_series_per_active_pool(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $p1 = $this->pool('Competição', $inst);
        $p2 = $this->pool('Lazer', $inst);
        $this->record($p1, ['ph' => 7.4]);
        $this->record($p2, ['ph' => 7.1]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'multi-piscina')
            ->set('selectedMetric', 'ph');

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('multi-piscina', $payload['mode']);
        $this->assertCount(2, $payload['series']);
        $this->assertSame('Leiria — Competição', $payload['series'][0]['label']);
        $this->assertNotSame($payload['series'][0]['cor'], $payload['series'][1]['cor']);
        $this->assertSame('pH', $payload['metrica']['label']);
    }

    public function test_multi_piscina_respects_nadador_salvador_scope(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $p1 = $this->pool('Competição', $inst);
        $p2 = $this->pool('Lazer', $inst);
        $this->record($p1, ['ph' => 7.4]);
        $this->record($p2, ['ph' => 7.1]);

        $ns = User::factory()->create();
        $ns->syncRoles(['nadador_salvador']);
        $ns->piscinas()->sync([$p1->id]);
        $this->actingAs($ns);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'multi-piscina')
            ->set('selectedMetric', 'ph');

        $payload = $widget->instance()->getChartPayload();

        $this->assertCount(1, $payload['series']);
        $this->assertSame('Leiria — Competição', $payload['series'][0]['label']);
    }

    public function test_corrected_records_excluded_and_ns_fallback_used(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);

        $original = $this->record($pool, ['ph' => 6.0]);
        $this->record($pool, ['ph' => 7.4, 'e_correcao' => true, 'corrige_registo_id' => $original->id]);
        $this->record($pool, ['ph' => null, 'ns_ph' => 7.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('mode', 'dual')
            ->set('leftMetric', 'ph')
            ->set('rightMetric', 'cloro_livre')
            ->set('poolSelecionada', (string) $pool->id);

        $payload = $widget->instance()->getChartPayload();
        $ys = array_column($payload['series'][0]['data'], 'y');

        $this->assertNotContains(6.0, $ys);
        $this->assertContains(7.2, $ys);
    }
}
