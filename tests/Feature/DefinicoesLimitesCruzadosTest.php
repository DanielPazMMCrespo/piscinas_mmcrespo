<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Pages\Definicoes;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * `DailyRecord::avaliarConformidade()` testa isBelowMin antes de isAboveMax e
 * devolve no primeiro `true`. Com `ph_min` >= `ph_max`, todo o valor de pH
 * fica simultaneamente abaixo do mínimo e acima do máximo, e o código
 * responde sempre "abaixo do mínimo" — falso alarme permanente no dashboard,
 * no semáforo do formulário, nos incidentes automáticos (3 violações/dia
 * dispara um) e no livro sanitário em PDF. `Definicoes` não tinha nenhuma
 * validação cruzada entre os pares min/max antes desta correção.
 */
class DefinicoesLimitesCruzadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => UserRole::ADMIN, 'guard_name' => 'web']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        return $admin;
    }

    public function test_ph_min_maior_que_ph_max_e_recusado_e_nao_grava(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Definicoes::class)
            ->fillForm(['ph_min' => '8.0', 'ph_max' => '6.9'], 'form')
            ->call('save');

        $this->assertDatabaseMissing('app_settings', ['key' => 'ph_min']);
        $this->assertDatabaseMissing('app_settings', ['key' => 'ph_max']);
    }

    public function test_ph_min_igual_a_ph_max_tambem_e_recusado(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Definicoes::class)
            ->fillForm(['ph_min' => '7.0', 'ph_max' => '7.0'], 'form')
            ->call('save');

        $this->assertDatabaseMissing('app_settings', ['key' => 'ph_min']);
    }

    public function test_novo_maximo_abaixo_do_minimo_ja_gravado_tambem_e_recusado(): void
    {
        // O admin só mexe no cloro_livre_max desta vez; o mínimo (0.5) já
        // estava gravado de uma sessão anterior. Só olhar para o que vem do
        // form deixava passar esta combinação — o valor "efetivo" tem de
        // misturar o novo com o que já está na base.
        AppSetting::create([
            'key' => 'cloro_livre_min',
            'value' => '1.5',
            'group' => 'geral',
            'label' => 'Cloro Livre Minimo',
            'type' => 'string',
        ]);

        $this->actingAs($this->admin());

        Livewire::test(Definicoes::class)
            ->fillForm(['cloro_livre_max' => '1.0'], 'form')
            ->call('save');

        $this->assertDatabaseMissing('app_settings', ['key' => 'cloro_livre_max']);
    }

    public function test_limites_validos_continuam_a_gravar_normalmente(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(Definicoes::class)
            ->fillForm(['ph_min' => '6.9', 'ph_max' => '8.0'], 'form')
            ->call('save')
            ->assertHasNoErrors();

        // `AppSetting::$casts['value']` é 'json' — um valor de string fica
        // gravado com aspas à volta (`"6.9"`), não em bruto.
        $this->assertDatabaseHas('app_settings', ['key' => 'ph_min', 'value' => json_encode('6.9')]);
        $this->assertDatabaseHas('app_settings', ['key' => 'ph_max', 'value' => json_encode('8.0')]);
    }
}
