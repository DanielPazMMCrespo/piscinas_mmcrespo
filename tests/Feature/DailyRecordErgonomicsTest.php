<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Filament\Resources\DailyRecordResource\Pages\ListDailyRecords;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyRecordErgonomicsTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private User $nadador;

    private Installation $instalacao;

    private Pool $piscina;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR, 'guard_name' => 'web']);

        $this->instalacao = Installation::create([
            'name' => 'Complexo Aquático Leiria',
            'morada' => 'Av. Principal',
            'active' => true,
        ]);

        $this->piscina = Pool::create([
            'installation_id' => $this->instalacao->id,
            'name' => 'Piscina Competição',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'volume' => 1000,
            'active' => true,
        ]);

        $this->tecnico = User::factory()->create(['name' => 'Técnico Manutenção']);
        $this->tecnico->assignRole(UserRole::TECNICO);

        $this->nadador = User::factory()->create(['name' => 'Nadador Salvador']);
        $this->nadador->assignRole(UserRole::NADADOR_SALVADOR);
        $this->nadador->piscinas()->attach($this->piscina->id);
    }

    public function test_comma_in_decimal_inputs_is_normalized_silently(): void
    {
        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->instalacao->id,
                'pools' => [
                    $this->piscina->id => [
                        'ns_ph' => '7,35',
                        'ns_cloro_livre' => '1,15',
                        'ns_cloro_total' => '1,45',
                        'ns_temperatura' => '27,5',
                        'banhistas' => 12,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->piscina->id,
            'ns_ph' => 7.35,
            'ns_cloro_livre' => 1.15,
            'ns_cloro_total' => 1.45,
            'banhistas' => 12,
        ]);
    }

    public function test_banhistas_is_visible_for_ns_and_hidden_for_technician(): void
    {
        $prefixo = "pools.{$this->piscina->id}";

        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->fillForm(['installation_id' => $this->instalacao->id])
            ->assertFormFieldIsVisible("{$prefixo}.banhistas");

        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(['installation_id' => $this->instalacao->id])
            ->assertFormFieldIsHidden("{$prefixo}.banhistas");
    }

    public function test_standard_save_redirects_to_dashboard(): void
    {
        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->instalacao->id,
                'pools' => [
                    $this->piscina->id => [
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar')
            ->assertRedirect('/admin');
    }

    public function test_save_and_new_resets_form_without_redirect(): void
    {
        Livewire::actingAs($this->nadador)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->instalacao->id,
                'pools' => [
                    $this->piscina->id => [
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.2,
                        'ns_cloro_total' => 1.5,
                        'ns_temperatura' => 27.0,
                    ],
                ],
            ])
            ->call('validarERegistosGuardar', true)
            ->assertNoRedirect();

        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->piscina->id,
            'ns_ph' => 7.4,
        ]);
    }

    public function test_list_records_renders_tabs(): void
    {
        DailyRecord::factory()->create([
            'pool_id' => $this->piscina->id,
            'user_id' => $this->tecnico->id,
            'registado_em' => now(),
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.2,
            'ns_cloro_total' => 1.5,
        ]);

        Livewire::actingAs($this->tecnico)
            ->test(ListDailyRecords::class)
            ->assertSuccessful();
    }
}
