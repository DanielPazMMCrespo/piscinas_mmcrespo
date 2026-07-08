<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyRecordTimerRetrolavagemTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;
    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $installation = Installation::factory()->create(['name' => 'Maceira']);
        $this->pool = Pool::factory()->create(['installation_id' => $installation->id, 'name' => 'Maceira']);
    }

    public function test_timer_nao_aparece_sem_retrolavagem_ativa(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(['pool_id' => $this->pool->id, 'filtro_faz_retrolavagem' => false])
            ->assertDontSee('Timer — Retrolavagem');
    }

    public function test_timer_aparece_com_duracoes_corretas_quando_retrolavagem_ativa(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(['pool_id' => $this->pool->id, 'filtro_faz_retrolavagem' => true])
            ->assertSee('Timer — Retrolavagem')
            ->assertSee('duracaoDefaultSegundos: 300', false)
            ->assertSee('Timer — Enxaguamento')
            ->assertSee('duracaoDefaultSegundos: 120', false);
    }
}
