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
     * Teste: Nadador-salvador tem por padrão a instalação do seu pool atribuído.
     */
    public function test_swimmer_defaults_to_assigned_pool_installation(): void
    {
        // Associar piscina de Leiria ao nadador
        $this->nadador->piscinas()->attach($this->competicao->id);

        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->assertFormFieldExists('installation_id')
            ->assertFormSet([
                'installation_id' => $this->leiria->id,
            ]);
    }

    /**
     * Regressão: a foto do quadro NS é obrigatória. O erro tem de aparecer como
     * erro do campo (ns_foto) — antes, o fluxo de confirmação apanhava a
     * ValidationException não importada como \Throwable e mostrava só a
     * mensagem genérica "O formulário expirou", deixando o NS preso sem saber
     * qual o campo em falta.
     */
    public function test_swimmer_missing_board_photo_surfaces_field_error(): void
    {
        $this->nadador->piscinas()->attach($this->competicao->id);

        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertHasFormErrors(['ns_foto']);
    }

    public function test_swimmer_can_successfully_create_daily_record(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->nadador->piscinas()->attach([$this->competicao->id, $this->lazer->id]);

        $file = \Illuminate\Http\UploadedFile::fake()->create('board.jpg', 100, 'image/jpeg');

        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'ns_foto' => [$file],
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                    ],
                    $this->lazer->id => [
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 28.0,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();
    }

    public function test_swimmer_with_partial_pools_assigned(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        // Apenas piscina Competição atribuída ao nadador, mas Leiria tem Competição e Lazer
        $this->nadador->piscinas()->attach($this->competicao->id);

        $file = \Illuminate\Http\UploadedFile::fake()->create('board.jpg', 100, 'image/jpeg');

        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                'ns_foto' => [$file],
                'pools' => [
                    $this->competicao->id => [
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();
    }
}
