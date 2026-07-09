<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
    private function valoresSlot(float $ph, float $cloroLivre, float $nsPh): array
    {
        return [
            'ph' => $ph,
            'cloro_livre' => $cloroLivre,
            'cloro_total' => $cloroLivre + 0.5,
            'temperatura' => 27.0,
            'transparencia' => 1,
            'ns_ph' => $nsPh,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 26.0,
        ];
    }

    public function test_selecionar_multiplas_piscinas_cria_registos_separados_com_valores_distintos(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'pool_id' => $this->competicao->id,
                'outras_piscinas_visita' => [$this->lazer->id, $this->infantil->id],
                'registado_em' => now(),
                'ns_foto' => [UploadedFile::fake()->create('ns_foto.jpg', 10)],
                'piscinas' => [
                    0 => $this->valoresSlot(7.4, 0.8, 7.2),
                    1 => $this->valoresSlot(7.1, 1.0, 7.0),
                    2 => $this->valoresSlot(7.6, 0.6, 7.3),
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 3);

        $competicao = DailyRecord::where('pool_id', $this->competicao->id)->first();
        $lazer = DailyRecord::where('pool_id', $this->lazer->id)->first();
        $infantil = DailyRecord::where('pool_id', $this->infantil->id)->first();

        $this->assertNotNull($competicao);
        $this->assertNotNull($lazer);
        $this->assertNotNull($infantil);

        $this->assertSame(7.4, (float) $competicao->ph);
        $this->assertSame(7.1, (float) $lazer->ph);
        $this->assertSame(7.6, (float) $infantil->ph);

        $this->assertSame(7.2, (float) $competicao->ns_ph);
        $this->assertSame(7.0, (float) $lazer->ns_ph);
        $this->assertSame(7.3, (float) $infantil->ns_ph);

        $this->assertNotEmpty($competicao->ns_foto);
        $this->assertSame($competicao->ns_foto, $lazer->ns_foto);
        $this->assertSame($competicao->ns_foto, $infantil->ns_foto);
    }

    public function test_registo_sem_selecionar_outras_piscinas_mantem_fluxo_normal(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'pool_id' => $this->competicao->id,
                'registado_em' => now(),
                'ns_foto' => [UploadedFile::fake()->create('ns_foto.jpg', 10)],
                'piscinas' => [
                    0 => $this->valoresSlot(7.4, 0.8, 7.2),
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 1);
        $this->assertDatabaseHas('daily_records', ['pool_id' => $this->competicao->id]);
    }
}
