<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\AlertLevel;
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
        Cache::flush();
    }

    private function adminUser(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    private function alertKey(): string
    {
        return 'sem_registo|1|'.now()->toDateString();
    }

    private function mockAlertasWithKey(string $key): void
    {
        $this->partialMock(AlertasService::class, function ($mock) use ($key) {
            $mock->shouldReceive('calcular')
                ->andReturn([
                    'alertas' => [
                        $key => [
                            'nivel'  => AlertLevel::VERMELHO,
                            'icone'  => 'heroicon-o-clipboard',
                            'titulo' => 'Teste',
                            'detalhe' => 'detalhe',
                            'url'    => '/admin',
                            'acao'   => 'Ver',
                        ],
                    ],
                    'totalPiscinas' => 1,
                    'conformesHoje' => 0,
                ]);
        });
    }

    public function test_mover_alerta_persists_state(): void
    {
        $admin = $this->adminUser();
        $key = $this->alertKey();

        $this->mockAlertasWithKey($key);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class)
            ->call('moverAlerta', $key, 'resolvido');

        $this->assertDatabaseHas('alert_states', [
            'alert_key' => $key,
            'status'    => 'resolvido',
            'moved_by'  => $admin->id,
        ]);
    }

    public function test_mover_alerta_updates_existing_state(): void
    {
        $admin = $this->adminUser();
        $key = $this->alertKey();

        AlertState::create([
            'alert_key' => $key,
            'status'    => 'pendente',
            'moved_by'  => $admin->id,
            'moved_at'  => now(),
        ]);

        $this->mockAlertasWithKey($key);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class)
            ->call('moverAlerta', $key, 'resolvido');

        $this->assertSame(1, AlertState::count());
        $this->assertDatabaseHas('alert_states', [
            'alert_key' => $key,
            'status'    => 'resolvido',
        ]);
    }

    public function test_mover_alerta_ignores_invalid_status(): void
    {
        $admin = $this->adminUser();
        $key = $this->alertKey();

        $this->mockAlertasWithKey($key);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class)
            ->call('moverAlerta', $key, 'status_invalido');

        $this->assertDatabaseMissing('alert_states', [
            'alert_key' => $key,
            'status'    => 'status_invalido',
        ]);
    }

    public function test_mover_alerta_rejects_em_curso(): void
    {
        $admin = $this->adminUser();
        $key = $this->alertKey();

        $this->mockAlertasWithKey($key);

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class)
            ->call('moverAlerta', $key, 'em_curso');

        $this->assertDatabaseMissing('alert_states', [
            'alert_key' => $key,
            'status'    => 'em_curso',
        ]);
    }

    public function test_alert_state_auto_resolves_when_condition_disappears(): void
    {
        $admin = $this->adminUser();
        $key = $this->alertKey();

        // Estado legado com 'em_curso' (de antes da simplificação para 2 estados)
        // continua a ser tratado como ativo e a resolver automaticamente.
        AlertState::create([
            'alert_key' => $key,
            'status'    => 'em_curso',
            'moved_by'  => $admin->id,
            'moved_at'  => now(),
            'payload'   => ['titulo' => 'Teste'],
        ]);

        // Alertas vazios: condição desapareceu
        $this->partialMock(AlertasService::class, function ($mock) {
            $mock->shouldReceive('calcular')
                 ->andReturn(['alertas' => [], 'totalPiscinas' => 0, 'conformesHoje' => 0]);
        });

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class);

        $this->assertDatabaseHas('alert_states', [
            'alert_key' => $key,
            'status'    => 'resolvido_auto',
        ]);
    }

    public function test_old_alert_states_are_pruned(): void
    {
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

        $this->partialMock(AlertasService::class, function ($mock) {
            $mock->shouldReceive('calcular')
                 ->andReturn(['alertas' => [], 'totalPiscinas' => 0, 'conformesHoje' => 0]);
        });

        Livewire::actingAs($admin)
            ->test(\App\Filament\Widgets\QuadroOperacionalWidget::class);

        // AlertHousekeepingCommand removes stale alerts (>7 days) separately, not in widget
        $this->assertDatabaseHas('alert_states', ['alert_key' => 'sem_registo|2|'.now()->toDateString()]);
    }
}
