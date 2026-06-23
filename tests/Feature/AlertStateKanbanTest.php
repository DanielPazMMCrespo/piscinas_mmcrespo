<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AlertState;
use App\Models\User;
use App\Services\AlertasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AlertStateKanbanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        AlertasService::resetMemo();
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_mover_alerta_persists_state(): void
    {
        $admin = $this->adminUser();

        $this->mock(AlertasService::class)
            ->shouldReceive('calcular')
            ->andReturn(['alertas' => [], 'totalPiscinas' => 0, 'conformesHoje' => 0]);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class)
            ->call('moverAlerta', 'sem_registo|1|'.now()->toDateString(), 'em_curso');

        $this->assertDatabaseHas('alert_states', [
            'alert_key' => 'sem_registo|1|'.now()->toDateString(),
            'status'    => 'em_curso',
            'moved_by'  => $admin->id,
        ]);
    }

    public function test_mover_alerta_updates_existing_state(): void
    {
        $admin = $this->adminUser();

        AlertState::create([
            'alert_key' => 'sem_registo|1|'.now()->toDateString(),
            'status'    => 'pendente',
            'moved_by'  => $admin->id,
            'moved_at'  => now(),
        ]);

        $this->mock(AlertasService::class)
            ->shouldReceive('calcular')
            ->andReturn(['alertas' => [], 'totalPiscinas' => 0, 'conformesHoje' => 0]);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class)
            ->call('moverAlerta', 'sem_registo|1|'.now()->toDateString(), 'resolvido');

        $this->assertSame(1, AlertState::count());
        $this->assertDatabaseHas('alert_states', [
            'alert_key' => 'sem_registo|1|'.now()->toDateString(),
            'status'    => 'resolvido',
        ]);
    }

    public function test_mover_alerta_ignores_invalid_status(): void
    {
        $admin = $this->adminUser();

        $this->mock(AlertasService::class)
            ->shouldReceive('calcular')
            ->andReturn(['alertas' => [], 'totalPiscinas' => 0, 'conformesHoje' => 0]);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class)
            ->call('moverAlerta', 'sem_registo|1|'.now()->toDateString(), 'status_invalido');

        $this->assertDatabaseCount('alert_states', 0);
    }

    public function test_old_alert_states_are_pruned(): void
    {
        Cache::flush();

        AlertState::create([
            'alert_key' => 'sem_registo|1|2020-01-01',
            'status'    => 'pendente',
            'moved_at'  => now()->subDays(8),
        ]);

        AlertState::create([
            'alert_key' => 'sem_registo|2|'.now()->toDateString(),
            'status'    => 'pendente',
            'moved_at'  => now(),
        ]);

        $admin = $this->adminUser();

        $this->mock(AlertasService::class)
            ->shouldReceive('calcular')
            ->andReturn(['alertas' => [], 'totalPiscinas' => 0, 'conformesHoje' => 0]);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class);

        $this->assertDatabaseCount('alert_states', 1);
        $this->assertDatabaseMissing('alert_states', ['alert_key' => 'sem_registo|1|2020-01-01']);
        $this->assertDatabaseHas('alert_states', ['alert_key' => 'sem_registo|2|'.now()->toDateString()]);
    }
}
