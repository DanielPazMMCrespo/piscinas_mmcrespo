<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\GlobalSearch\PaginasGlobalSearchProvider;
use App\Filament\Pages\EsquemaPiscina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O Esquema do Circuito saiu da sidebar por decisao de auditoria: nenhum dos
 * quatro papeis conseguiu nomear uma tarefa do dia a dia que so ele resolva, e
 * os atalhos que tem duplicam os cartoes do dashboard um toque mais longe.
 *
 * Mas e o unico mapa visual do circuito da agua, e isso vale numa passagem de
 * instalacao. Por isso a rota fica viva e a pesquisa global continua a
 * encontra-la. Este teste guarda as duas metades da decisao: se alguem devolver
 * o item ao menu, ou se alguem matar a rota, um destes falha.
 */
class EsquemaForaDaSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_nao_aparece_no_menu(): void
    {
        $this->assertFalse(
            EsquemaPiscina::shouldRegisterNavigation(),
            'O Esquema nao volta a ocupar um lugar na vista diaria de ninguem.'
        );
    }

    public function test_a_rota_continua_viva_para_o_tecnico(): void
    {
        $tecnico = User::factory()->create();
        $tecnico->assignRole('tecnico');

        $this->actingAs($tecnico)
            ->get(EsquemaPiscina::getUrl())
            ->assertSuccessful();
    }

    public function test_a_rota_continua_viva_para_o_admin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(EsquemaPiscina::getUrl())
            ->assertSuccessful();
    }

    /**
     * A pesquisa global e o unico caminho que sobra para o Esquema. Se deixar
     * de o encontrar, a pagina fica alcancavel so por URL decorado.
     */
    public function test_continua_a_ser_encontrada_pela_pesquisa_global(): void
    {
        $tecnico = User::factory()->create();
        $tecnico->assignRole('tecnico');
        $this->actingAs($tecnico);

        $provider = new PaginasGlobalSearchProvider;

        foreach (['esquema', 'circuito', 'bomba', 'filtro'] as $termo) {
            $categorias = $provider->getResults($termo)?->getCategories() ?? [];

            $titulos = collect($categorias['Páginas'] ?? [])
                ->map(fn ($resultado) => $resultado->title)
                ->all();

            $this->assertContains(
                'Esquema',
                $titulos,
                "Ctrl+K com \"{$termo}\" tem de encontrar o Esquema."
            );
        }
    }
}
