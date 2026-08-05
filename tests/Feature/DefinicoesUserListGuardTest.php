<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Pages\Definicoes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DefinicoesUserListGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
    }

    /**
     * Livewire expõe qualquer método público do componente a quem estiver
     * autenticado, mesmo que o Blade só o chame dentro de um bloco @if admin —
     * a visibilidade no Blade não é fronteira de autorização.
     */
    public function test_tecnico_nao_consegue_obter_a_lista_de_utilizadores_via_chamada_direta(): void
    {
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        User::factory()->count(3)->create()->each(fn (User $u) => $u->assignRole(UserRole::TECNICO));

        $this->actingAs($tecnico);

        Livewire::test(Definicoes::class)
            ->call('getUsuariosNotificacoes')
            ->assertReturned(fn ($resultado) => empty($resultado));

        Livewire::test(Definicoes::class)
            ->call('getUsuariosLista')
            ->assertReturned(fn ($resultado) => $resultado === []);
    }

    public function test_admin_continua_a_obter_a_lista_de_utilizadores(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        User::factory()->count(3)->create()->each(fn (User $u) => $u->assignRole(UserRole::TECNICO));

        $this->actingAs($admin);

        Livewire::test(Definicoes::class)
            ->call('getUsuariosNotificacoes')
            ->assertReturned(fn ($resultado) => count($resultado) === 4);
    }
}
