<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Pages\AnaliseParametros;
use App\Filament\Widgets\CloroPhChartWidget;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AnaliseParametrosErgonomicsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $nadador;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach ([UserRole::ADMIN, UserRole::NADADOR_SALVADOR] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole(UserRole::NADADOR_SALVADOR);

        $installation = Installation::factory()->create();
        $this->pool = Pool::factory()->create(['installation_id' => $installation->id]);
        $this->nadador->piscinas()->attach($this->pool->id);

        Cache::flush();
    }

    public function test_admin_can_access_analise_parametros_page_with_segmented_tabs(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AnaliseParametros::class)
            ->assertSuccessful()
            ->assertSee('Evolução & Gráficos')
            ->assertSee('Auditoria DGS (CN 14/DA)');
    }

    public function test_removed_widgets_are_not_rendered_on_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(AnaliseParametros::class)
            ->assertSuccessful()
            ->assertDontSee('Estabilidade das medições')
            ->assertDontSee('Consumo de químicos por piscina');
    }

    public function test_chart_widget_defaults_to_manual_metrics_ph_and_cloro_livre(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CloroPhChartWidget::class)
            ->assertSet('leftMetric', 'ph')
            ->assertSet('rightMetric', 'cloro_livre');
    }

    public function test_chart_widget_supports_30d_period(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CloroPhChartWidget::class)
            ->call('setPeriod', '30d')
            ->assertSet('period', '30d');
    }

    public function test_swimmer_only_sees_chart_and_not_auditoria_tab(): void
    {
        Livewire::actingAs($this->nadador)
            ->test(AnaliseParametros::class)
            ->assertSuccessful()
            ->assertDontSee('Auditoria DGS (CN 14/DA)');
    }

    public function test_swimmer_cannot_change_period_to_30d(): void
    {
        Livewire::actingAs($this->nadador)
            ->test(CloroPhChartWidget::class)
            ->assertSet('period', '12h')
            ->call('setPeriod', '30d')
            ->assertSet('period', '12h');
    }
}
