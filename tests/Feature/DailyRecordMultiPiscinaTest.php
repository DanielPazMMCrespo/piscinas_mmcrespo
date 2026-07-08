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
        foreach (['admin', 'tecnico'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

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

    /**
     * @return array<string, mixed>
     */
    private function estadoValido(int $poolId): array
    {
        return [
            'pool_id' => $poolId,
            'registado_em' => now(),
            'ph' => 7.4,
            'cloro_livre' => 0.8,
            'cloro_total' => 1.5,
            'temperatura' => 27.0,
            'transparencia' => 1,
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 26.0,
            'ns_foto' => [\Illuminate\Http\UploadedFile::fake()->create('ns_foto.jpg', 10)],
        ];
    }

    public function test_guardar_e_avancar_cria_registo_e_avanca_para_a_proxima_piscina(): void
    {
        $estado = $this->estadoValido($this->competicao->id);
        $estado['outras_piscinas_visita'] = [$this->lazer->id, $this->infantil->id];

        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($estado)
            ->call('guardarEAvancar')
            ->assertHasNoFormErrors()
            ->assertSet('data.pool_id', $this->lazer->id)
            ->assertSet('filaRestante', [$this->infantil->id])
            ->assertSet('filaTotal', 3);

        $this->assertDatabaseHas('daily_records', ['pool_id' => $this->competicao->id]);
        $this->assertDatabaseCount('daily_records', 1);
    }

    public function test_fila_completa_cria_um_registo_por_piscina(): void
    {
        $estado1 = $this->estadoValido($this->competicao->id);
        $estado1['outras_piscinas_visita'] = [$this->lazer->id, $this->infantil->id];

        $componente = Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($estado1)
            ->call('guardarEAvancar')
            ->assertHasNoFormErrors();

        $componente
            ->fillForm($this->estadoValido($this->lazer->id))
            ->call('guardarEAvancar')
            ->assertHasNoFormErrors()
            ->assertSet('data.pool_id', $this->infantil->id)
            ->assertSet('filaRestante', []);

        $componente
            ->fillForm($this->estadoValido($this->infantil->id))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 3);
        $this->assertDatabaseHas('daily_records', ['pool_id' => $this->competicao->id]);
        $this->assertDatabaseHas('daily_records', ['pool_id' => $this->lazer->id]);
        $this->assertDatabaseHas('daily_records', ['pool_id' => $this->infantil->id]);
    }

    public function test_registo_sem_selecionar_outras_piscinas_mantem_fluxo_normal(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($this->estadoValido($this->competicao->id))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 1);
    }
}
