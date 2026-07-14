<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\IncidentResource\Pages\CreateIncident;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentInstallationRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $nadador;
    private Installation $leiria;
    private Installation $maceira;
    private Pool $competicao;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole('nadador_salvador');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
        ]);

        $this->maceira = Installation::factory()->create(['name' => 'Maceira']);
        Pool::factory()->create([
            'installation_id' => $this->maceira->id,
            'name' => 'Maceira',
        ]);
    }

    public function test_swimmer_only_sees_installation_of_assigned_pool(): void
    {
        $this->nadador->piscinas()->attach($this->competicao->id);

        $options = Livewire::actingAs($this->nadador)
            ->test(CreateIncident::class)
            ->instance()
            ->form
            ->getFlatFields()['installation_id']
            ->getOptions();

        $this->assertArrayHasKey($this->leiria->id, $options);
        $this->assertArrayNotHasKey($this->maceira->id, $options);
    }

    public function test_admin_sees_all_installations(): void
    {
        $options = Livewire::actingAs($this->admin)
            ->test(CreateIncident::class)
            ->instance()
            ->form
            ->getFlatFields()['installation_id']
            ->getOptions();

        $this->assertArrayHasKey($this->leiria->id, $options);
        $this->assertArrayHasKey($this->maceira->id, $options);
    }
}
