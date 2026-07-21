<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\CloroPhChartWidget;
use App\Filament\Widgets\PainelPiscinasWidget;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NSDashboardRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $nadador;
    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'nadador_salvador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole('nadador_salvador');

        $installation = Installation::factory()->create();
        $this->pool = Pool::factory()->create(['installation_id' => $installation->id]);
        $this->nadador->piscinas()->attach($this->pool->id);

        Cache::flush();
    }

    public function test_swimmer_dashboard_hides_daily_records_progress_bar(): void
    {
        Livewire::actingAs($this->nadador)
            ->test(PainelPiscinasWidget::class)
            ->assertSee('Piscinas Conformes')
            ->assertDontSee('Registos Hoje');
    }

    public function test_admin_dashboard_shows_daily_records_progress_bar(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PainelPiscinasWidget::class)
            ->assertSee('Registos Hoje');
    }

    public function test_swimmer_chart_defaults_to_12h_and_cannot_change_period(): void
    {
        Livewire::actingAs($this->nadador)
            ->test(CloroPhChartWidget::class)
            ->assertSet('period', '12h')
            ->call('setPeriod', '7d')
            ->assertSet('period', '12h');
    }

    public function test_admin_chart_defaults_to_7d_and_can_change_period(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CloroPhChartWidget::class)
            ->assertSet('period', '7d')
            ->call('setPeriod', '24h')
            ->assertSet('period', '24h');
    }

    public function test_swimmer_chart_axis_options_exclude_turbidez(): void
    {
        $options = Livewire::actingAs($this->nadador)
            ->test(CloroPhChartWidget::class)
            ->instance()
            ->form
            ->getFlatFields()['leftMetric']
            ->getOptions();

        $this->assertArrayNotHasKey('transparencia', $options);
        $this->assertArrayHasKey('ph', $options);
        $this->assertArrayHasKey('cloro_livre', $options);
        $this->assertArrayHasKey('cloro_total', $options);
        $this->assertArrayHasKey('temperatura', $options);
        $this->assertArrayHasKey('controlador_ph', $options);
        $this->assertArrayHasKey('controlador_orp', $options);
        $this->assertArrayHasKey('controlador_temp', $options);
    }

    public function test_admin_chart_axis_options_include_turbidez(): void
    {
        $options = Livewire::actingAs($this->admin)
            ->test(CloroPhChartWidget::class)
            ->instance()
            ->form
            ->getFlatFields()['leftMetric']
            ->getOptions();

        $this->assertArrayHasKey('transparencia', $options);
    }

    public function test_swimmer_widget_quick_actions_only_contains_registo_rapido(): void
    {
        $widget = Livewire::actingAs($this->nadador)
            ->test(PainelPiscinasWidget::class)
            ->instance();

        $reflection = new \ReflectionClass($widget);
        $method = $reflection->getMethod('getViewData');
        $method->setAccessible(true);
        $viewData = $method->invoke($widget);

        $poolData = $viewData['piscinas']->first();
        $acoes = collect($poolData['acoes_rapidas']);

        $this->assertCount(1, $acoes);
        $this->assertEquals('Registo Rápido', $acoes->first()['label']);
    }

    public function test_admin_widget_quick_actions_contains_all_actions(): void
    {
        $widget = Livewire::actingAs($this->admin)
            ->test(PainelPiscinasWidget::class)
            ->instance();

        $reflection = new \ReflectionClass($widget);
        $method = $reflection->getMethod('getViewData');
        $method->setAccessible(true);
        $viewData = $method->invoke($widget);

        $poolData = $viewData['piscinas']->first();
        $acoes = collect($poolData['acoes_rapidas']);

        $this->assertCount(5, $acoes);
        $labels = $acoes->pluck('label')->toArray();
        $this->assertContains('Registo Rápido', $labels);
        $this->assertContains('Análise rápida', $labels);
        $this->assertContains('Lavar filtro', $labels);
        $this->assertContains('Torneira', $labels);
        $this->assertContains('Contador', $labels);
    }
}
