<?php

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DailyRecordIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function novaPiscina(): Pool
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);

        return Pool::create([
            'installation_id' => $inst->id,
            'name'            => 'Competição',
            'type'            => 'Interior',
            'temp_min'        => 26,
            'temp_max'        => 27,
            'active'          => true,
        ]);
    }

    private function novoRegisto(Pool $pool, User $autor): DailyRecord
    {
        return DailyRecord::create([
            'pool_id'       => $pool->id,
            'user_id'       => $autor->id,
            'registado_em'  => now(),
            'cloro_livre'   => 1.0,
            'cloro_total'   => 1.2,
            'ph'            => 7.4,
            'temperatura'   => 26.5,
            'transparencia' => 2,
        ]);
    }

    public function test_apenas_admin_pode_editar_e_eliminar_registos(): void
    {
        $pool = $this->novaPiscina();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $tecnico = User::factory()->create();
        $tecnico->assignRole('tecnico');
        $ns = User::factory()->create();
        $ns->assignRole('nadador_salvador');

        $registo = $this->novoRegisto($pool, $tecnico);

        $this->actingAs($tecnico);
        $this->assertFalse(DailyRecordResource::canEdit($registo), 'Técnico não deve poder editar');
        $this->assertFalse(DailyRecordResource::canDelete($registo), 'Técnico não deve poder eliminar');

        $this->actingAs($ns);
        $this->assertFalse(DailyRecordResource::canEdit($registo), 'NS não deve poder editar');

        $this->actingAs($admin);
        $this->assertTrue(DailyRecordResource::canEdit($registo), 'Admin deve poder editar');
        $this->assertTrue(DailyRecordResource::canDelete($registo), 'Admin deve poder eliminar');
    }

    public function test_correcao_cria_registo_ligado_e_exclui_original_das_estatisticas(): void
    {
        $pool = $this->novaPiscina();
        $autor = User::factory()->create();
        $autor->assignRole('tecnico');

        $original = $this->novoRegisto($pool, $autor);

        $correcao = DailyRecord::create([
            'pool_id'            => $original->pool_id,
            'user_id'            => $autor->id,
            'registado_em'       => $original->registado_em,
            'cloro_livre'        => 1.1,
            'cloro_total'        => 1.3,
            'ph'                 => 7.5,
            'temperatura'        => 26.6,
            'transparencia'      => 2,
            'e_correcao'         => true,
            'corrige_registo_id' => $original->id,
            'razao_correcao'     => 'Valor de pH mal lido.',
        ]);

        $this->assertTrue($correcao->e_correcao);
        $this->assertEquals($original->id, $correcao->corrige_registo_id);
        $this->assertEquals(1, $original->correcoes()->count());

        // O original (já substituído) é excluído; a correção entra.
        $validos = DailyRecord::whereDoesntHave('correcoes')->pluck('id');
        $this->assertFalse($validos->contains($original->id), 'Original substituído não deve contar');
        $this->assertTrue($validos->contains($correcao->id), 'Correção deve contar');
    }

    public function test_helpers_de_conformidade_cn14da(): void
    {
        $pool = $this->novaPiscina();
        $autor = User::factory()->create();

        $foraDeLimite = DailyRecord::create([
            'pool_id'       => $pool->id,
            'user_id'       => $autor->id,
            'registado_em'  => now(),
            'cloro_livre'   => 5.0,   // acima de 2.0
            'cloro_total'   => 5.2,
            'ph'            => 9.0,   // acima de 8.0
            'temperatura'   => 40.0,  // acima do limite da piscina (27)
            'transparencia' => 2,
        ]);
        $foraDeLimite->setRelation('piscina', $pool);

        $this->assertFalse($foraDeLimite->phConforme());
        $this->assertFalse($foraDeLimite->cloroLivreConforme());
        $this->assertFalse($foraDeLimite->temperaturaConforme());
    }
}
