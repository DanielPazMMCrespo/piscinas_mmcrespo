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

class DailyRecordMultiPiscinaTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;
    private Installation $leiria;
    private Pool $competicao;
    private Pool $lazer;
    private Pool $infantil;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create(['installation_id' => $this->leiria->id, 'name' => 'Competição']);
        $this->lazer = Pool::factory()->create(['installation_id' => $this->leiria->id, 'name' => 'Lazer']);
        $this->infantil = Pool::factory()->create(['installation_id' => $this->leiria->id, 'name' => 'Infantil']);
    }

    public function test_checkbox_outras_piscinas_aparece_para_instalacao_com_multiplas_piscinas(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(['pool_id' => $this->competicao->id])
            ->assertSee('Também registar nesta visita')
            ->assertSee('Lazer')
            ->assertSee('Infantil');
    }

    public function test_checkbox_outras_piscinas_nao_aparece_para_instalacao_de_piscina_unica(): void
    {
        $maceira = Installation::factory()->create(['name' => 'Maceira']);
        $pool = Pool::factory()->create(['installation_id' => $maceira->id, 'name' => 'Maceira']);

        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(['pool_id' => $pool->id])
            ->assertDontSee('Também registar nesta visita');
    }
}
