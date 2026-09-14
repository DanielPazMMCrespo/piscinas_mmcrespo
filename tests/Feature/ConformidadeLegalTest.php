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

    // --- Banda única de Cloro Livre: 0,5 a 2,0 mg/L independente de pH -----------

    public function test_cloro_livre_ate_dois_com_ph_baixo_e_conforme(): void
    {
        $pool = $this->criarPiscina();
        // Na prática DGS, 1,8 mg/L a pH 7,0 está perfeitamente seguro e conforme (< 2,0)
        $registo = $this->criarRegisto($pool, ph: 7.0, cloroLivre: 1.8);

        $this->assertTrue(
            $registo->cloroLivreConforme(),
            'Cloro livre 1,8 mg/L está abaixo de 2,0 mg/L e dentro dos limites regulamentares.'
        );

        $this->assertSame(
            EstadoConformidade::VERDE,
            DailyRecord::avaliarConformidade('cloro_livre', 1.8, $pool, ph: 7.0)['estado'],
            'O semáforo do formulário deve ficar verde.'
        );
    }

    public function test_cloro_livre_com_ph_alto_acima_de_meio_e_conforme(): void
    {
        $pool = $this->criarPiscina();
        // Na prática DGS, 0,7 mg/L a pH 7,8 está conforme (>= 0,5 e <= 2,0)
        $registo = $this->criarRegisto($pool, ph: 7.8, cloroLivre: 0.7);

        $this->assertTrue(
            $registo->cloroLivreConforme(),
            'Cloro livre 0,7 mg/L está acima de 0,5 mg/L e conforme.'
        );

        $this->assertSame(
            EstadoConformidade::VERDE,
            DailyRecord::avaliarConformidade('cloro_livre', 0.7, $pool, ph: 7.8)['estado'],
            'O semáforo do formulário deve ficar verde.'
        );
    }

    public function test_cloro_livre_acima_de_dois_e_violacao(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.2, cloroLivre: 2.5);

        $this->assertFalse(
            $registo->cloroLivreConforme(),
            'Cloro livre 2,5 mg/L excede o limite máximo de 2,0 mg/L.'
        );

        $this->assertSame(
            EstadoConformidade::VERMELHO,
            DailyRecord::avaliarConformidade('cloro_livre', 2.5, $pool, ph: 7.2)['estado'],
            'O semáforo do formulário deve ficar vermelho.'
        );
    }

    public function test_cloro_livre_abaixo_de_meio_e_violacao(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.2, cloroLivre: 0.2);

        $this->assertFalse(
            $registo->cloroLivreConforme(),
            'Cloro livre 0,2 mg/L está abaixo do limite mínimo de 0,5 mg/L.'
        );

        $this->assertSame(
            EstadoConformidade::VERMELHO,
            DailyRecord::avaliarConformidade('cloro_livre', 0.2, $pool, ph: 7.2)['estado'],
            'O semáforo do formulário deve ficar vermelho.'
        );
    }

    // --- Sem pH ----------------------------------------------------------------

    public function test_sem_ph_usa_a_banda_global_e_e_conforme(): void
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
            'Cloro livre 1,8 mg/L sem pH deve ser conforme dentro de 0,5–2,0 mg/L.'
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

    // --- Mensagens e Alertas de Violação ---------------------------------------

    public function test_a_mensagem_explica_a_violacao_de_cloro_livre(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.2, cloroLivre: 2.5);

        $mensagens = array_column($registo->listarViolacoes(), 'mensagem');
        $texto = implode(' | ', $mensagens);

        $this->assertStringContainsString('2,0', $texto, 'Tem de citar o máximo da banda aplicada.');
        $this->assertStringContainsString('acima do máximo', $texto);
    }

    public function test_violacao_de_banda_aparece_na_lista_de_violacoes(): void
    {
        $pool = $this->criarPiscina();
        $registo = $this->criarRegisto($pool, ph: 7.2, cloroLivre: 2.5);

        $parametros = array_column($registo->listarViolacoes(), 'parametro');

        $this->assertContains(
            'cloro_livre',
            $parametros,
            'Uma violação legal tem de disparar o alerta.'
        );
    }
}
