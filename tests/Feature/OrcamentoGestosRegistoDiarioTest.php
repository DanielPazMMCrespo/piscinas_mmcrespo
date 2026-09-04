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
use Tests\Support\ContadorGestos;
use Tests\TestCase;

/**
 * A regua de rapidez, automatizada.
 *
 * O alvo desta auditoria: registar as 3 piscinas de Leiria num telemovel de
 * 390 px com menos atrito do que o Skimmer. O Skimmer foi estimado em ~27-30
 * toques para o mesmo cenario (3 piscinas, 4 leituras cada) -- estimado, nao
 * cronometrado, porque nao houve acesso a uma conta real.
 *
 * Sem um contador, "esta mais rapido" e uma opiniao. Este teste conta os gestos
 * a partir do schema real do formulario.
 */
class OrcamentoGestosRegistoDiarioTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O alvo.
     *
     * Deliberadamente NAO tentamos os 18 gestos que a analise do Skimmer
     * sugeriu: chegar la exigia pre-preencher os quatro parametros legais com a
     * leitura do dia anterior, e isso torna "nao medi, so gravei"
     * indistinguivel de "medi e deu o mesmo" num documento em que uma
     * autoridade de saude se apoia. As leituras continuam a ser escritas.
     */
    private const ORCAMENTO_ALVO = 30;

    /**
     * Travao de catraca: o numero medido hoje. Nao pode subir. Cada corte
     * baixa-o, e a partir dai fica guardado -- se alguem voltar a por um campo
     * diario atras de uma seccao fechada, ou acrescentar um campo obrigatorio,
     * este teste diz logo qual foi e quanto custou.
     *
     * Historico: 45 (antes da auditoria) -> 42 (pressao do filtro fora da
     * seccao fechada) -> 39 (a entrada eram 2 gestos e nao 4, medido; e a
     * confirmacao ao gravar passou a aparecer so quando ha violacao).
     */
    private const ORCAMENTO_ATUAL = 39;

    private User $tecnico;

    private Installation $leiria;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);

        // As tres piscinas reais de Leiria, com os volumes reais.
        foreach ([['Competição', 900.0], ['Lazer', 600.0], ['Infantil', 50.0]] as [$nome, $volume]) {
            Pool::factory()->create([
                'installation_id' => $this->leiria->id,
                'name' => $nome,
                'volume' => $volume,
            ]);
        }
    }

    /**
     * @return array{total: int, entrada: int, gravacao: int, aberturas: int, campos_diarios: int, por_piscina: array<string, array{aberturas: int, campos: int, gestos: int, secoes_fechadas: array<int, string>, campos_encontrados: array<int, string>}>}
     */
    private function medir(): array
    {
        $pagina = Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(['installation_id' => $this->leiria->id])
            ->instance();

        return ContadorGestos::contar($pagina->getForm('form'));
    }

    /**
     * @param  array{total: int, entrada: int, gravacao: int, aberturas: int, campos_diarios: int, por_piscina: array<string, array{aberturas: int, campos: int, gestos: int, secoes_fechadas: array<int, string>, campos_encontrados: array<int, string>}>}  $medida
     */
    private function explicar(array $medida): string
    {
        $linhas = ['Gestos: '.$medida['total'].' (alvo: '.self::ORCAMENTO_ALVO.', catraca: '.self::ORCAMENTO_ATUAL.')'];
        $linhas[] = '  entrada: '.$medida['entrada'].'  gravacao: '.$medida['gravacao'];

        foreach ($medida['por_piscina'] as $nome => $p) {
            $fechadas = $p['secoes_fechadas'] === [] ? '—' : implode(', ', $p['secoes_fechadas']);
            $linhas[] = '  '.$nome.': '.$p['gestos'].' gestos ('.$p['campos'].' campos, '.$p['aberturas'].' aberturas: '.$fechadas.')';
        }

        return implode("\n", $linhas);
    }

    public function test_o_numero_de_gestos_nao_sobe(): void
    {
        $medida = $this->medir();

        $this->assertLessThanOrEqual(
            self::ORCAMENTO_ATUAL,
            $medida['total'],
            "O registo das 3 piscinas de Leiria ficou mais lento.\n".$this->explicar($medida)
        );
    }

    /**
     * O alvo. Enquanto nao chegarmos la, este teste fica incompleto em vez de
     * vermelho: o trabalho esta a andar e o numero exato esta na mensagem.
     */
    public function test_o_alvo_de_trinta_gestos(): void
    {
        $medida = $this->medir();

        if ($medida['total'] > self::ORCAMENTO_ALVO) {
            $this->markTestIncomplete(
                'Faltam '.($medida['total'] - self::ORCAMENTO_ALVO)." gestos para o alvo.\n".$this->explicar($medida)
            );
        }

        $this->assertLessThanOrEqual(self::ORCAMENTO_ALVO, $medida['total']);
    }

    /**
     * Nenhuma secção com um campo do dia a dia pode vir fechada. Abrir uma
     * secção só para escrever a pressão do filtro é atrito puro, todos os
     * dias, três vezes.
     */
    public function test_nenhum_campo_diario_esta_atras_de_uma_seccao_fechada(): void
    {
        $medida = $this->medir();

        $this->assertSame(
            0,
            $medida['aberturas'],
            "Ha secções fechadas no caminho diário.\n".$this->explicar($medida)
        );
    }

    /**
     * Guarda contra a régua se degradar em silêncio: se alguém acrescentar um
     * campo diário novo, este número sobe e o teste diz qual.
     */
    public function test_o_numero_de_campos_diarios_por_piscina_esta_declarado(): void
    {
        $medida = $this->medir();

        $this->assertCount(3, $medida['por_piscina'], 'Leiria tem 3 piscinas.');

        foreach ($medida['por_piscina'] as $nome => $p) {
            $this->assertLessThanOrEqual(
                6,
                $p['campos'],
                $nome.' pede '.$p['campos'].' campos por dia. Seis é o que a lei e o contador exigem; acima disso é campo a mais.'
            );
        }
    }
}
