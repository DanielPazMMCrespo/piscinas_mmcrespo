<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O cloro total inferior ao cloro livre era apanhado dentro do create(), a
 * seguir ao slide-over de confirmação, e o `false` que a verificação devolvia
 * era indistinguível de "não consegui o lock" — o técnico via
 * "Submissão duplicada" em vez do erro real.
 */
class DailyRecordCloroValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private Installation $leiria;

    private Pool $competicao;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
        ]);
    }

    /** @param array<string, mixed> $valores */
    private function formulario(array $valores): array
    {
        return [
            'installation_id' => $this->leiria->id,
            'pools' => [
                $this->competicao->id => array_merge([
                    'ns_ph' => 7.2,
                    'ns_temperatura' => 27.0,
                ], $valores),
            ],
        ];
    }

    public function test_cloro_invertido_nao_e_reportado_como_submissao_duplicada(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($this->formulario([
                'ns_cloro_livre' => 2.0,
                'ns_cloro_total' => 1.5,
            ]))
            ->call('create')
            ->assertNotified('Corrija os campos assinalados');

        $this->assertSame(0, DailyRecord::count());
    }

    public function test_cloro_invertido_nao_chega_ao_modal_de_confirmacao(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($this->formulario([
                'ns_cloro_livre' => 2.0,
                'ns_cloro_total' => 1.5,
            ]))
            ->call('validarERegistosGuardar')
            ->assertActionNotMounted('confirmarCriacao')
            ->assertNotified('Corrija os campos assinalados');

        $this->assertSame(0, DailyRecord::count());
    }

    public function test_registo_valido_continua_a_gravar_com_o_lock_manual(): void
    {
        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($this->formulario([
                'ns_cloro_livre' => 1.0,
                'ns_cloro_total' => 1.2,
            ]))
            ->call('create')
            ->assertNotified('Registo guardado!');

        $this->assertSame(1, DailyRecord::count());
    }

    public function test_lock_e_libertado_e_permite_gravar_um_segundo_registo(): void
    {
        $componente = Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($this->formulario([
                'ns_cloro_livre' => 1.0,
                'ns_cloro_total' => 1.2,
            ]))
            ->call('create');

        $componente
            ->fillForm($this->formulario([
                'ns_cloro_livre' => 1.1,
                'ns_cloro_total' => 1.3,
            ]))
            ->call('create')
            ->assertNotified('Registo guardado!');

        $this->assertSame(2, DailyRecord::count());
    }
}
