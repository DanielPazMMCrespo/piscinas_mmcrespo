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

class DailyRecordSeedPoolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_escolher_instalacao_semeia_estado_das_piscinas(): void
    {
        $installation = Installation::create(['name' => 'Leiria', 'morada' => 'X', 'active' => true]);
        $poolA = Pool::create(['installation_id' => $installation->id, 'name' => 'A', 'type' => 'competition', 'temp_min' => 26, 'temp_max' => 27, 'volume' => 900, 'active' => true, 'ordem_bombas' => 1, 'ordem_filtros' => 1]);
        $poolB = Pool::create(['installation_id' => $installation->id, 'name' => 'B', 'type' => 'recreation', 'temp_min' => 28, 'temp_max' => 30, 'volume' => 600, 'active' => true, 'ordem_bombas' => 2, 'ordem_filtros' => 2]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $component = Livewire::test(CreateDailyRecord::class)
            ->set('data.installation_id', $installation->id);

        $pools = $component->get('data.pools');

        $this->assertIsArray($pools);
        $this->assertArrayHasKey((string) $poolA->id, $pools);
        $this->assertArrayHasKey((string) $poolB->id, $pools);
        $this->assertFalse($pools[(string) $poolA->id]['filtro_faz_retrolavagem']);
        $this->assertArrayHasKey('filtro_foto_enxaguamento', $pools[(string) $poolA->id]);
    }
}
