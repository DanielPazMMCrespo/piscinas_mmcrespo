<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\RelatorioPdf;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Valida a página Filament RelatorioPdf via Livewire: as regras de data são
 * impostas pelo formulário (data_fim >= data_inicio; nenhuma data no futuro).
 */
class RelatorioPdfInvalidDateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Installation $installation;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        $this->installation = Installation::factory()->create(['name' => 'Leiria']);
        $this->pool = Pool::factory()->create([
            'installation_id' => $this->installation->id,
            'name' => 'Competição',
        ]);
    }

    public function test_pdf_rejeita_data_fim_anterior_data_inicio(): void
    {
        Livewire::actingAs($this->user)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => (string) $this->pool->id,
                'data_inicio' => now()->subDays(2)->toDateString(),
                'data_fim' => now()->subDays(10)->toDateString(), // anterior ao início
            ])
            ->call('exportar')
            ->assertHasFormErrors(['data_fim']);
    }

    public function test_pdf_rejeita_data_no_futuro(): void
    {
        Livewire::actingAs($this->user)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => (string) $this->pool->id,
                'data_inicio' => now()->addDays(5)->toDateString(), // futuro
                'data_fim' => now()->addDays(10)->toDateString(),
            ])
            ->call('exportar')
            ->assertHasFormErrors(['data_inicio']);
    }

    public function test_pdf_aceita_periodo_valido(): void
    {
        Livewire::actingAs($this->user)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => 'todas',
                // Intervalo relativo: com startOfMonth o teste falhava no dia 1 de
                // cada mês (data fim anterior à data início).
                'data_inicio' => now()->subDays(7)->toDateString(),
                'data_fim' => now()->subDay()->toDateString(),
            ])
            ->call('exportar')
            ->assertHasNoFormErrors();
    }

    public function test_pdf_permite_mais_de_7_dias_e_limita_a_31_dias_em_modo_todos_registos_controlador(): void
    {
        $startDate = now()->subDays(40)->toDateString();
        $expectedEndDate = now()->subDays(40)->addDays(RelatorioPdf::MAX_DIAS_CONTROLADOR_TODOS - 1)->toDateString();

        $component = Livewire::actingAs($this->user)
            ->test(RelatorioPdf::class)
            ->fillForm([
                'installation_id' => $this->installation->id,
                'pool_id' => (string) $this->pool->id,
                'data_inicio' => $startDate,
                'data_fim' => now()->subDays(1)->toDateString(), // 40 dias
                'controlador_modo' => 'todos',
            ]);

        // O período é ajustado para 31 dias (1 mês completo) e o relatório é gerado no mesmo clique
        $this->assertNotNull($component->instance()->exportar());
        $this->assertEquals($expectedEndDate, $component->get('data.data_fim'));
    }
}
