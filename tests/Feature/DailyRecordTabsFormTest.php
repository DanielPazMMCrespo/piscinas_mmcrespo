<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O formulário passou de Wizard (um passo por tema, um fieldset por piscina em
 * cada passo) para um separador por piscina com todas as secções dentro. Estes
 * testes cobrem o que a mudança de statePath pode quebrar em silêncio: secções
 * condicionais e campos que deixem de chegar à base de dados.
 */
class DailyRecordTabsFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => UserRole::TECNICO, 'guard_name' => 'web']);
    }

    /**
     * O form builder memoiza sonda/último registo em propriedades static sem hook
     * de reset — sem isto o memo sobrevive para outros testes que reusem o id.
     */
    protected function tearDown(): void
    {
        $resets = [
            'sondaMemo' => [],
            'sondaMomentoMemo' => [],
            'modoRapido' => false,
            'poolFixo' => null,
            'ultimoRegistoMemo' => [],
        ];

        foreach ($resets as $prop => $valorInicial) {
            $reflection = new ReflectionProperty(DailyRecordFormBuilder::class, $prop);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $valorInicial);
        }

        parent::tearDown();
    }

    private function ambiente(): array
    {
        $installation = Installation::factory()->create(['tanques_verificaveis' => true]);

        $competicao = Pool::factory()->create([
            'installation_id' => $installation->id,
            'name' => 'Competição',
            'volume' => 900,
        ]);

        $lazer = Pool::factory()->create([
            'installation_id' => $installation->id,
            'name' => 'Lazer',
            'volume' => 600,
        ]);

        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        return [$installation, $competicao, $lazer, $tecnico];
    }

    public function test_secoes_de_filtro_dependem_do_toggle_dentro_do_separador_da_piscina(): void
    {
        [$installation, $competicao, $lazer, $tecnico] = $this->ambiente();

        $component = Livewire::actingAs($tecnico)
            ->test(CreateDailyRecord::class)
            ->set('data.installation_id', $installation->id);

        $component->assertFormFieldIsHidden("pools.{$competicao->id}.filtro_foto_enxaguamento");
        $component->assertFormFieldIsHidden("pools.{$competicao->id}.filtro_foto_posicao_normal");

        $component->set("data.pools.{$competicao->id}.filtro_faz_retrolavagem", true);

        $component->assertFormFieldIsVisible("pools.{$competicao->id}.filtro_foto_enxaguamento");
        $component->assertFormFieldIsVisible("pools.{$competicao->id}.filtro_foto_posicao_normal");

        // O toggle de uma piscina não pode arrastar as secções da outra.
        $component->assertFormFieldIsHidden("pools.{$lazer->id}.filtro_foto_enxaguamento");
    }

    public function test_registo_completo_de_duas_piscinas_grava_todos_os_campos(): void
    {
        [$installation, $competicao, $lazer, $tecnico] = $this->ambiente();

        Livewire::actingAs($tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $installation->id,
                'hora_colheita' => '09:30',
                'pools' => [
                    $competicao->id => [
                        'bomba_ferrada' => true,
                        'agua_modo' => 'off',
                        'contador_valor' => 1234.5,
                        'tanque_ok' => true,
                        'filtro_faz_retrolavagem' => true,
                        'numero_lavagens_filtro' => 2,
                        'pressao_filtro' => 0.85,
                        'ns_ph' => 7.2,
                        'ns_cloro_livre' => 1.0,
                        'ns_cloro_total' => 1.2,
                        'ns_temperatura' => 26.5,
                        'banhistas' => 12,
                        'observacoes' => 'Sem ocorrências.',
                    ],
                    $lazer->id => [
                        'bomba_ferrada' => true,
                        'agua_modo' => 'off',
                        'tanque_ok' => true,
                        'filtro_faz_retrolavagem' => false,
                        'ns_ph' => 7.4,
                        'ns_cloro_livre' => 1.1,
                        'ns_cloro_total' => 1.3,
                        'ns_temperatura' => 28.5,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 2);

        $registoCompeticao = DailyRecord::where('pool_id', $competicao->id)->firstOrFail();

        $this->assertSame($tecnico->id, $registoCompeticao->user_id);
        $this->assertSame('09:30', $registoCompeticao->registado_em->format('H:i'));
        $this->assertTrue((bool) $registoCompeticao->bomba_ferrada);
        $this->assertSame('off', $registoCompeticao->agua_modo);
        $this->assertSame(1234.5, (float) $registoCompeticao->contador_valor);
        $this->assertTrue((bool) $registoCompeticao->filtro_faz_retrolavagem);
        $this->assertSame(2, (int) $registoCompeticao->numero_lavagens_filtro);
        $this->assertSame(0.85, (float) $registoCompeticao->pressao_filtro);
        $this->assertSame(7.2, (float) $registoCompeticao->ns_ph);
        $this->assertSame(1.0, (float) $registoCompeticao->ns_cloro_livre);
        $this->assertSame(1.2, (float) $registoCompeticao->ns_cloro_total);
        $this->assertSame(26.5, (float) $registoCompeticao->ns_temperatura);
        $this->assertSame(12, (int) $registoCompeticao->banhistas);
        $this->assertSame('Sem ocorrências.', $registoCompeticao->observacoes);

        $registoLazer = DailyRecord::where('pool_id', $lazer->id)->firstOrFail();

        $this->assertSame(7.4, (float) $registoLazer->ns_ph);
        $this->assertSame(28.5, (float) $registoLazer->ns_temperatura);
    }
}
