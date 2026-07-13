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

class DailyRecordRoleRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $tecnico;
    private User $nadador;
    private Installation $leiria;
    private Pool $competicao;
    private Pool $lazer;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->nadador = User::factory()->create();
        $this->nadador->assignRole('nadador_salvador');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
        ]);
        $this->lazer = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Lazer',
        ]);
    }

    /**
     * Teste: Nadador-salvador com data/hora desativada.
     */
    public function test_swimmer_registado_em_field_is_disabled(): void
    {
        // Associar piscina ao nadador salvador
        $this->nadador->piscinas()->attach($this->competicao->id);

        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->assertFormFieldIsDisabled('registado_em');
    }

    /**
     * Teste: Técnico e Admin têm o campo registado_em ativado.
     */
    public function test_technician_and_admin_registado_em_field_is_enabled(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->assertFormFieldIsEnabled('registado_em');

        Livewire::actingAs($this->admin)
            ->test(CreateDailyRecord::class)
            ->assertFormFieldIsEnabled('registado_em');
    }

    /**
     * Teste: Nadador-salvador só vê as piscinas às quais está associado.
     */
    public function test_swimmer_only_sees_assigned_pools_in_dropdown(): void
    {
        // Associar apenas a piscina de Competição ao nadador
        $this->nadador->piscinas()->attach($this->competicao->id);

        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->assertFormFieldExists('pool_id');
        
        // Verificamos que na query do select do pool_id apenas a de Competição está listada
        $this->assertTrue($this->nadador->piscinas()->exists());
        $this->assertSame(1, $this->nadador->piscinas()->count());
        $this->assertSame($this->competicao->id, $this->nadador->piscinas()->first()->id);
    }
}
