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

    public function test_stacked_mode_returns_multiple_graphs_with_series(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);
        $this->record($pool, ['ph' => 7.4, 'cloro_livre' => 1.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('poolSelecionada', (string) $pool->id)
            ->set('graphsConfig', [
                ['visible' => true, 'metrics' => ['ph']],
                ['visible' => true, 'metrics' => ['cloro_livre']],
                ['visible' => false, 'metrics' => ['temperatura']],
            ]);

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('stacked', $payload['mode']);
        $this->assertCount(2, $payload['graphs']); // Only the two visible ones

        $this->assertCount(1, $payload['graphs'][0]['series']);
        $this->assertSame('ph', $payload['graphs'][0]['series'][0]['key']);
        
        $this->assertCount(1, $payload['graphs'][1]['series']);
        $this->assertSame('cloro_livre', $payload['graphs'][1]['series'][0]['key']);
    }

    public function test_stacked_mode_filters_invalid_keys(): void
    {
        $this->actingAs($this->admin());
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $pool = $this->pool('Competição', $inst);
        $this->record($pool, ['ph' => 7.4, 'cloro_livre' => 1.2]);

        $widget = Livewire::test(CloroPhChartWidget::class)
            ->set('poolSelecionada', (string) $pool->id)
            ->set('graphsConfig', [
                ['visible' => true, 'metrics' => ['ph', 'cloro_livre', 'inexistente']],
            ]);

        $payload = $widget->instance()->getChartPayload();

        $this->assertSame('stacked', $payload['mode']);
        $this->assertCount(1, $payload['graphs']);
        $this->assertCount(2, $payload['graphs'][0]['series']);
        
        $keys = array_column($payload['graphs'][0]['series'], 'key');
        $this->assertSame(['ph', 'cloro_livre'], $keys);
        $this->assertArrayHasKey('yMin', $payload['graphs'][0]['series'][0]);
        $this->assertArrayHasKey('yMax', $payload['graphs'][0]['series'][0]);
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
            ->set('poolSelecionada', (string) $pool->id)
            ->set('graphsConfig', [
                ['visible' => true, 'metrics' => ['ph']],
            ]);

        $payload = $widget->instance()->getChartPayload();
        $ys = array_column($payload['graphs'][0]['series'][0]['data'], 'y');

        $this->assertNotContains(6.0, $ys);
        $this->assertContains(7.2, $ys);
    }
}
