<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GlobalSearchPoolScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_nadador_salvador_nao_ve_registos_de_piscinas_fora_do_seu_scope_na_pesquisa_por_dia(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);

        $installation = Installation::factory()->create();

        $poolPermitida = Pool::factory()->create(['installation_id' => $installation->id]);
        $poolProibida = Pool::factory()->create(['installation_id' => $installation->id, 'name' => 'Piscina Restrita']);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $ns = User::factory()->create();
        $ns->assignRole(UserRole::NADADOR_SALVADOR);
        $ns->piscinas()->attach($poolPermitida->id);

        DailyRecord::create([
            'pool_id' => $poolPermitida->id,
            'user_id' => $tecnico->id,
            'registado_em' => now()->setDay(15),
        ]);
        DailyRecord::create([
            'pool_id' => $poolProibida->id,
            'user_id' => $tecnico->id,
            'registado_em' => now()->setDay(15),
        ]);

        $this->actingAs($ns);

        $results = DailyRecordResource::getGlobalSearchResults('dia 15');

        $this->assertTrue($results->contains(fn ($r) => str_contains($r->title, $poolPermitida->name)));
        $this->assertFalse($results->contains(fn ($r) => str_contains($r->title, 'Restrita')));
    }
}
