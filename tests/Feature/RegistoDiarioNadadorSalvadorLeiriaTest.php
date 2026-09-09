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

class RegistoDiarioNadadorSalvadorLeiriaTest extends TestCase
{
    use RefreshDatabase;

    private User $ns;

    private Installation $leiria;

    private Pool $competicao;

    private Pool $lazer;

    private Pool $infantil;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ns = User::factory()->create(['name' => 'Nadador Leiria']);
        $this->ns->assignRole('nadador_salvador');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
            'volume' => 900,
        ]);
        $this->lazer = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Lazer',
            'volume' => 600,
        ]);
        $this->infantil = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Infantil',
            'volume' => 50,
        ]);

        // Nadador com as 3 piscinas de Leiria atribuídas
        $this->ns->piscinas()->attach([
            $this->competicao->id,
            $this->lazer->id,
            $this->infantil->id,
        ]);
    }

    /**
     * Teste 1: Verificar se funciona quando o NS preenche as 3 piscinas de Leiria (comportamento por defeito).
     */
    public function test_ns_consegue_gravar_quando_preenche_as_tres_piscinas(): void
    {
        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->assertFormSet([
                'piscinas_selecionadas' => [
                    $this->competicao->id,
                    $this->lazer->id,
                    $this->infantil->id,
                ],
            ])
            ->set('data.ns_foto', ['guardado' => 'ns-fotos/quadro.jpg'])
            ->set("data.pools.{$this->competicao->id}.ns_ph", 7.2)
            ->set("data.pools.{$this->competicao->id}.ns_cloro_livre", 1.0)
            ->set("data.pools.{$this->competicao->id}.ns_cloro_total", 1.2)
            ->set("data.pools.{$this->competicao->id}.ns_temperatura", 27.0)
            ->set("data.pools.{$this->lazer->id}.ns_ph", 7.3)
            ->set("data.pools.{$this->lazer->id}.ns_cloro_livre", 1.1)
            ->set("data.pools.{$this->lazer->id}.ns_cloro_total", 1.3)
            ->set("data.pools.{$this->lazer->id}.ns_temperatura", 28.5)
            ->set("data.pools.{$this->infantil->id}.ns_ph", 7.4)
            ->set("data.pools.{$this->infantil->id}.ns_cloro_livre", 1.2)
            ->set("data.pools.{$this->infantil->id}.ns_cloro_total", 1.4)
            ->set("data.pools.{$this->infantil->id}.ns_temperatura", 29.0)
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 3);
        $this->assertEquals(3, DailyRecord::where('user_id', $this->ns->id)->count());
    }

    /**
     * Teste 2: O NS seleciona apenas 1 piscina (Infantil), desmarcando as outras duas.
     * Grava apenas essa piscina, sem erros de validação nas restantes.
     */
    public function test_ns_consegue_gravar_apenas_uma_piscina_ao_desmarcar_as_outras(): void
    {
        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->set('data.piscinas_selecionadas', [$this->infantil->id])
            ->set('data.ns_foto', ['guardado' => 'ns-fotos/quadro.jpg'])
            ->set("data.pools.{$this->infantil->id}.ns_ph", 7.4)
            ->set("data.pools.{$this->infantil->id}.ns_cloro_livre", 1.2)
            ->set("data.pools.{$this->infantil->id}.ns_cloro_total", 1.4)
            ->set("data.pools.{$this->infantil->id}.ns_temperatura", 29.0)
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 1);
        $registo = DailyRecord::first();
        $this->assertSame($this->infantil->id, $registo->pool_id);
        $this->assertEquals(7.40, (float) $registo->ns_ph);
        $this->assertEquals(1.20, (float) $registo->ns_cloro_livre);
        $this->assertSame($this->ns->id, $registo->user_id);
    }

    /**
     * Teste 3: O NS seleciona 2 piscinas (Competição e Lazer), deixando a Infantil de fora.
     */
    public function test_ns_consegue_gravar_duas_piscinas_ao_selecionar_apenas_duas(): void
    {
        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->set('data.piscinas_selecionadas', [$this->competicao->id, $this->lazer->id])
            ->set('data.ns_foto', ['guardado' => 'ns-fotos/quadro.jpg'])
            ->set("data.pools.{$this->competicao->id}.ns_ph", 7.2)
            ->set("data.pools.{$this->competicao->id}.ns_cloro_livre", 1.0)
            ->set("data.pools.{$this->competicao->id}.ns_cloro_total", 1.2)
            ->set("data.pools.{$this->competicao->id}.ns_temperatura", 27.0)
            ->set("data.pools.{$this->lazer->id}.ns_ph", 7.3)
            ->set("data.pools.{$this->lazer->id}.ns_cloro_livre", 1.1)
            ->set("data.pools.{$this->lazer->id}.ns_cloro_total", 1.3)
            ->set("data.pools.{$this->lazer->id}.ns_temperatura", 28.5)
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 2);
        $this->assertEquals(0, DailyRecord::where('pool_id', $this->infantil->id)->count());
        $this->assertEquals(1, DailyRecord::where('pool_id', $this->competicao->id)->count());
        $this->assertEquals(1, DailyRecord::where('pool_id', $this->lazer->id)->count());
    }

    /**
     * Teste 4: Desmarcar todas as piscinas impede a submissão.
     */
    public function test_desmarcar_todas_as_piscinas_da_erro_de_validacao(): void
    {
        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->set('data.piscinas_selecionadas', [])
            ->set('data.ns_foto', ['guardado' => 'ns-fotos/quadro.jpg'])
            ->call('validarERegistosGuardar')
            ->assertHasFormErrors(['piscinas_selecionadas']);

        $this->assertDatabaseCount('daily_records', 0);
    }

    /**
     * Teste 5: Piscina desmarcada com valores parciais não bloqueia nem cria registo na BD.
     */
    public function test_piscina_desmarcada_com_dados_parciais_nao_bloqueia_nem_grava_sujeira(): void
    {
        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->set('data.piscinas_selecionadas', [$this->infantil->id])
            ->set('data.ns_foto', ['guardado' => 'ns-fotos/quadro.jpg'])
            // Dados parciais/incompletos colocados na Competição (que está desmarcada)
            ->set("data.pools.{$this->competicao->id}.ns_ph", 7.2)
            // Dados completos na Infantil (que está selecionada)
            ->set("data.pools.{$this->infantil->id}.ns_ph", 7.4)
            ->set("data.pools.{$this->infantil->id}.ns_cloro_livre", 1.2)
            ->set("data.pools.{$this->infantil->id}.ns_cloro_total", 1.4)
            ->set("data.pools.{$this->infantil->id}.ns_temperatura", 29.0)
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 1);
        $this->assertEquals(0, DailyRecord::where('pool_id', $this->competicao->id)->count());
        $this->assertEquals(1, DailyRecord::where('pool_id', $this->infantil->id)->count());
    }
}
