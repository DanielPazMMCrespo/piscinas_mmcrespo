<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Gravar custava sempre dois toques: "Gravar Registos" e depois "Confirmar e
 * guardar" no slide-over de resumo. Tres vezes por dia, todos os dias, mesmo
 * num dia em que nada esta fora dos limites.
 *
 * A confirmacao existe para o tecnico reler o que vai entrar no livro
 * sanitario. Isso vale quando ha uma violacao a entrar; num dia conforme os
 * valores ja foram validados campo a campo pelo semaforo. E o mesmo critero que
 * o Kanban ja usava, onde so o vermelho exige confirmacao.
 *
 * Estes testes guardam as duas metades: o passo extra sai do dia normal e fica
 * onde importa.
 */
class ConfirmacaoSoQuandoHaViolacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private Installation $leiria;

    private Pool $competicao;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
            'volume' => 900.0,
            'temp_min' => 26.0,
            'temp_max' => 27.0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $leituras
     */
    private function preencher(array $leituras): Testable
    {
        $prefixo = "pools.{$this->competicao->id}";
        $estado = ['installation_id' => $this->leiria->id];

        foreach ($leituras as $campo => $valor) {
            $estado["{$prefixo}.{$campo}"] = $valor;
        }

        return Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm($estado);
    }

    /**
     * Água conforme: pH 7,2 (banda 6,9–7,4), cloro livre 1,0 (banda baixa
     * 0,5–1,2), cloro total 1,2 (combinado 0,2, abaixo de 0,5), temperatura
     * 26,5 (dentro de 26–27).
     */
    public function test_dia_conforme_grava_sem_pedir_confirmacao(): void
    {
        $pagina = $this->preencher([
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 26.5,
        ]);

        $this->assertFalse(
            $pagina->instance()->algumaLeituraForaDosLimites(),
            'Nada está fora dos limites — não há nada para reler.'
        );

        $pagina->call('validarERegistosGuardar');

        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->competicao->id,
            'ns_ph' => 7.2,
        ]);
    }

    /**
     * Cloro livre 1,8 com pH 7,0: a banda legal nessa faixa de pH é 0,5–1,2.
     * É a violação que a app declarava conforme antes de 395f2d7.
     */
    public function test_violacao_de_cloro_ainda_pede_confirmacao(): void
    {
        $pagina = $this->preencher([
            'ns_ph' => 7.0,
            'ns_cloro_livre' => 1.8,
            'ns_cloro_total' => 2.0,
            'ns_temperatura' => 26.5,
        ]);

        $this->assertTrue(
            $pagina->instance()->algumaLeituraForaDosLimites(),
            'Cloro 1,8 com pH 7,0 passa do máximo legal de 1,2 — tem de haver confirmação.'
        );

        $pagina->call('validarERegistosGuardar');

        $this->assertDatabaseCount('daily_records', 0);
        $pagina->assertActionMounted('confirmarCriacao');
    }

    public function test_ph_fora_dos_limites_pede_confirmacao(): void
    {
        $pagina = $this->preencher([
            'ns_ph' => 8.6,
            'ns_cloro_livre' => 1.5,
            'ns_cloro_total' => 1.6,
            'ns_temperatura' => 26.5,
        ]);

        $this->assertTrue($pagina->instance()->algumaLeituraForaDosLimites(), 'pH 8,6 passa do máximo de 8,0.');
    }

    public function test_temperatura_fora_da_gama_da_piscina_pede_confirmacao(): void
    {
        $pagina = $this->preencher([
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 34.0,
        ]);

        $this->assertTrue(
            $pagina->instance()->algumaLeituraForaDosLimites(),
            '34 °C numa piscina de competição (26–27) tem de ser confirmado.'
        );
    }

    /**
     * O registo gravado sem modal tem de ficar igual ao que ficava com modal.
     * O que se poupou foi um toque, não um campo.
     */
    public function test_o_registo_gravado_sem_modal_fica_completo(): void
    {
        $this->preencher([
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 1.0,
            'ns_cloro_total' => 1.2,
            'ns_temperatura' => 26.5,
            'contador_valor' => 1234.5,
        ])->call('validarERegistosGuardar');

        $registo = DailyRecord::first();

        $this->assertNotNull($registo);
        $this->assertSame($this->competicao->id, $registo->pool_id);
        $this->assertSame($this->tecnico->id, $registo->user_id);
        $this->assertEquals(7.2, (float) $registo->ns_ph);
        $this->assertEquals(1.0, (float) $registo->ns_cloro_livre);
        $this->assertEquals(1.2, (float) $registo->ns_cloro_total);
        $this->assertEquals(26.5, (float) $registo->ns_temperatura);
        $this->assertEquals(1234.5, (float) $registo->contador_valor);
    }
}
