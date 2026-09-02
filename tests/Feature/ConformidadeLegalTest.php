<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\EstadoConformidade;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Services\LimitesLegaisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Prova que o livro sanitário diz o que a lei diz.
 *
 * CN 14/DA (DGS 2009), Tabela 5 — tanques cobertos:
 *   - cloro livre 0,5–1,2 mg/L quando o pH está entre 6,9 e 7,4
 *   - cloro livre 1,0–2,0 mg/L quando o pH está entre 7,5 e 8,0
 *   - cloro combinado <= 0,5 mg/L
 *
 * A banda de cloro livre depende do pH. Uma banda fixa 0,5–2,0 é a união das
 * duas: aceita como conforme leituras que a lei considera violação, e o técnico
 * nunca é avisado. É a falha mais grave possível num livro de registo legal.
 */
class ConformidadeLegalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    private function criarPiscina(): Pool
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);

        return Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900.0,
            'active' => true,
        ]);
    }

    private function criarRegisto(Pool $pool, float $ph, float $cloroLivre, ?float $cloroTotal = null): DailyRecord
    {
        return DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => User::factory()->create()->id,
            'registado_em' => now(),
            'ph' => $ph,
            'cloro_livre' => $cloroLivre,
            'cloro_total' => $cloroTotal ?? $cloroLivre,
        ]);
    }

    // --- Banda baixa de pH: 6,9 a 7,4 -> cloro livre 0,5 a 1,2 -----------------

    public function test_cloro_alto_com_ph_baixo_e_violacao(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.0, cloroLivre: 1.8);

        $this->assertFalse(
            $registo->cloroLivreConforme(),
            'pH 7,0 com cloro livre 1,8 mg/L: a lei fixa o máximo em 1,2 nesta banda de pH.'
        );

        $this->assertSame(
            EstadoConformidade::VERMELHO,
            DailyRecord::avaliarConformidade('cloro_livre', 1.8, $pool, ph: 7.0)['estado'],
            'O semáforo do formulário tem de ficar vermelho, não verde.'
        );
    }

    public function test_cloro_dentro_da_banda_baixa_e_conforme(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.0, cloroLivre: 0.9);

        $this->assertTrue($registo->cloroLivreConforme());

        $this->assertSame(
            EstadoConformidade::VERDE,
            DailyRecord::avaliarConformidade('cloro_livre', 0.9, $pool, ph: 7.0)['estado']
        );
    }

    // --- Banda alta de pH: 7,5 a 8,0 -> cloro livre 1,0 a 2,0 ------------------

    public function test_cloro_baixo_com_ph_alto_e_violacao(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.8, cloroLivre: 0.7);

        $this->assertFalse(
            $registo->cloroLivreConforme(),
            'pH 7,8 com cloro livre 0,7 mg/L: a lei fixa o mínimo em 1,0 nesta banda de pH.'
        );

        $this->assertSame(
            EstadoConformidade::VERMELHO,
            DailyRecord::avaliarConformidade('cloro_livre', 0.7, $pool, ph: 7.8)['estado']
        );
    }

    public function test_cloro_dentro_da_banda_alta_e_conforme(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.8, cloroLivre: 1.6);

        $this->assertTrue($registo->cloroLivreConforme());
    }

    // --- Sem pH não há banda ---------------------------------------------------

    public function test_sem_ph_usa_a_banda_larga_e_nao_inventa_violacao(): void
    {
        $pool = $this->criarPiscina();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => User::factory()->create()->id,
            'registado_em' => now(),
            'cloro_livre' => 1.8,
        ]);

        $this->assertTrue(
            $registo->cloroLivreConforme(),
            'Sem pH não se sabe qual a banda. Não se declara violação por adivinhação.'
        );
    }

    // --- Cloro combinado -------------------------------------------------------

    public function test_cloro_combinado_acima_de_meio_e_violacao(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.2, cloroLivre: 1.0, cloroTotal: 1.55);

        $this->assertEqualsWithDelta(0.55, (float) $registo->cloro_combinado, 0.001);

        $this->assertFalse(
            $registo->cloroCombinadoConforme(),
            'A CN 14/DA fixa o cloro combinado em 0,5 mg/L, não 0,6.'
        );
    }

    public function test_cloro_combinado_no_limite_legal_e_conforme(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.2, cloroLivre: 1.0, cloroTotal: 1.5);

        $this->assertTrue($registo->cloroCombinadoConforme());
    }

    // --- Os limites novos não reescrevem o passado -----------------------------

    public function test_registo_anterior_a_vigencia_mantem_os_limites_antigos(): void
    {
        $pool = $this->criarPiscina();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => User::factory()->create()->id,
            'registado_em' => LimitesLegaisService::vigentesDesde()->subDay(),
            'ph' => 7.0,
            'cloro_livre' => 1.8,
            'cloro_total' => 2.35,
        ]);

        $this->assertTrue(
            $registo->cloroLivreConforme(),
            'Um mês já entregue à autoridade de saúde não é reavaliado por regras novas.'
        );

        $this->assertTrue(
            $registo->cloroCombinadoConforme(),
            'Combinado 0,55 era conforme no regime antigo (máximo 0,6).'
        );
    }

    public function test_registo_no_dia_da_vigencia_ja_usa_os_limites_novos(): void
    {
        $pool = $this->criarPiscina();

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => User::factory()->create()->id,
            'registado_em' => LimitesLegaisService::vigentesDesde(),
            'ph' => 7.0,
            'cloro_livre' => 1.8,
        ]);

        $this->assertFalse($registo->cloroLivreConforme());
    }

    // --- A mensagem tem de explicar a banda ------------------------------------

    public function test_a_mensagem_diz_qual_o_ph_que_escolheu_a_banda(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.0, cloroLivre: 1.8);

        $mensagens = array_column($registo->listarViolacoes(), 'mensagem');
        $texto = implode(' | ', $mensagens);

        $this->assertStringContainsString('1,2', $texto, 'Tem de citar o máximo da banda aplicada.');
        $this->assertStringContainsString('pH', $texto, 'Tem de dizer que foi o pH a escolher a banda.');
    }

    // --- A violação tem de chegar ao sino e ao Kanban --------------------------

    public function test_violacao_de_banda_aparece_na_lista_de_violacoes(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.0, cloroLivre: 1.8);

        $parametros = array_column($registo->listarViolacoes(), 'parametro');

        $this->assertContains(
            'cloro_livre',
            $parametros,
            'Uma violação legal tem de disparar o alerta, senão ninguém a corrige.'
        );
    }
}
