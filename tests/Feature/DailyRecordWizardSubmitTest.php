<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Prova end-to-end de que o wizard de 10 passos do DailyRecordResource grava
 * mesmo um registo (caminho de produção usado diariamente). Submete o FORMULÁRIO
 * Filament real, não só DailyRecord::create.
 */
class DailyRecordWizardSubmitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['admin', 'gestor', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_tecnico_grava_registo_pelo_wizard(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id, 'name' => 'Competição', 'type' => 'Interior',
            'temp_min' => 26, 'temp_max' => 27, 'volume' => 900, 'active' => true,
        ]);

        $tecnico = User::create([
            'name' => 'Técnico', 'email' => 'tecnico@teste.pt', 'password' => bcrypt('password'),
        ]);
        $tecnico->assignRole('tecnico');

        Livewire::actingAs($tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'pool_id' => $pool->id,
                'bomba_estado' => 'ferrada',
                'torneira_modo' => 'automatico',
                'ph' => 7.4,
                'cloro_livre' => 1.2,
                'cloro_total' => 1.5,
                'temperatura' => 28.0,
                'transparencia' => 3,
                'aspeto_agua' => 'visivel',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $pool->id,
            'user_id' => $tecnico->id,
            'bomba_estado' => 'ferrada',
            'aspeto_agua' => 'visivel',
        ]);
    }
}
