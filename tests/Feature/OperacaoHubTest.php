<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class OperacaoHubTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Limpar cache de permissões
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    public function test_guest_cannot_access_hub_page(): void
    {
        $response = $this->get('/admin/operacao-hub');
        $response->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_access_hub_page(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user);

        $response = $this->get('/admin/operacao-hub');
        $response->assertSuccessful();
        $response->assertSee('Registo Diário');
        $response->assertSee('Incidentes');
    }

    public function test_hub_page_has_modal_action(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        Livewire::actingAs($user)
            ->test(\App\Filament\Pages\OperacaoHub::class)
            ->assertActionVisible('registoDiario');
    }
}
