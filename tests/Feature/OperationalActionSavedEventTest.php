<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OperationalActionResource;
use App\Models\Installation;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * BUG-07: o rascunho de Ações Operacionais em localStorage nunca era limpo
 * depois de gravar com sucesso — ao contrário do Registo Diário, que dispara
 * 'dailyRecordSaved' para o app.js limpar o rascunho. Sem esse sinal, um
 * rascunho antigo podia ser reenviado como ação duplicada em modo offline.
 * Este teste prova o lado do servidor: o evento tem de ser despachado.
 */
class OperationalActionSavedEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_operational_action_saved_event_after_create(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $installation = Installation::create([
            'name' => 'Complexo Aquático',
            'morada' => 'Av. Principal',
            'active' => true,
        ]);

        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Principal',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'volume' => 1000,
            'active' => true,
        ]);

        $this->actingAs($admin);

        Livewire::test(OperationalActionResource\Pages\CreateOperationalAction::class)
            ->fillForm([
                'pool_id' => $pool->id,
                'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
                'registado_em' => now()->toDateTimeString(),
                'dados.filtro_nome' => 'Filtro 1',
                'dados.duracao_min' => 15,
                'dados.pressao_antes_bar' => 1.45,
                'dados.pressao_depois_bar' => 0.85,
                'observacoes' => 'Lavagem quinzenal do filtro principal',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertDispatched('operationalActionSaved');
    }
}
