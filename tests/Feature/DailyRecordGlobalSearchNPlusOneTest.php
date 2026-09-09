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

class DailyRecordGlobalSearchNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole(UserRole::ADMIN);

        $inst = Installation::factory()->create(['name' => 'Complexo Central']);
        $this->pool = Pool::factory()->create(['installation_id' => $inst->id, 'name' => 'Piscina Principal']);

        DailyRecord::factory()->create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->admin->id,
            'registado_em' => now(),
            'observacoes' => 'Análise matinal sem anomalias',
        ]);
    }

    public function test_global_search_eager_loads_relationships(): void
    {
        $query = DailyRecordResource::getGlobalSearchEloquentQuery();
        $eagerLoads = array_keys($query->getEagerLoads());

        $this->assertContains('piscina.instalacao', $eagerLoads);
        $this->assertContains('utilizador', $eagerLoads);
    }

    public function test_global_search_returns_results_with_details(): void
    {
        $this->actingAs($this->admin);

        $results = DailyRecordResource::getGlobalSearchResults('matinal');
        $this->assertNotEmpty($results);

        $first = $results->first();
        $this->assertStringContainsString('Piscina Principal', $first->title);
        $this->assertEquals('Complexo Central', $first->details['Instalação']);
        $this->assertEquals($this->admin->name, $first->details['Técnico']);
    }
}
