<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource;
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
 * Quantos gestos custa CHEGAR ao formulario.
 *
 * O contador de gestos declarava 4 (abrir "Registos Diarios", tocar "Criar",
 * abrir o select de instalacao, escolher). Os dois ultimos nao existem quando o
 * select ja vem preenchido -- e o `default()` tem uma cascata que resolve isso
 * na maioria dos casos reais. Estes testes fixam esse comportamento, para o
 * numero declarado no contador ser medido e nao assumido.
 */
class EntradaNoRegistoDiarioTest extends TestCase
{
    use RefreshDatabase;

    private Installation $leiria;

    private Installation $maceira;

    private Pool $competicao;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['tecnico', 'nadador_salvador'] as $papel) {
            Role::firstOrCreate(['name' => $papel, 'guard_name' => 'web']);
        }

        $this->leiria = Installation::factory()->create(['name' => 'Leiria', 'active' => true]);
        $this->maceira = Installation::factory()->create(['name' => 'Maceira', 'active' => true]);

        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
            'volume' => 900.0,
        ]);
        Pool::factory()->create([
            'installation_id' => $this->maceira->id,
            'name' => 'Maceira',
            'volume' => 170.0,
        ]);
    }

    private function tecnico(): User
    {
        $u = User::factory()->create();
        $u->assignRole('tecnico');

        return $u;
    }

    private function instalacaoEscolhida(User $utilizador): ?int
    {
        $estado = Livewire::actingAs($utilizador)
            ->test(CreateDailyRecord::class)
            ->get('data');

        return isset($estado['installation_id']) ? (int) $estado['installation_id'] : null;
    }

    /**
     * O caso mais comum: o técnico volta à instalação onde registou ontem.
     * Zero gestos para escolher a instalação.
     */
    public function test_a_instalacao_vem_da_ultima_que_o_tecnico_registou(): void
    {
        $tecnico = $this->tecnico();

        DailyRecord::create([
            'pool_id' => $this->competicao->id,
            'user_id' => $tecnico->id,
            'registado_em' => now()->subDay(),
            'ns_ph' => 7.4,
        ]);

        $this->assertSame(
            $this->leiria->id,
            $this->instalacaoEscolhida($tecnico),
            'Sem isto o técnico abre o select e escolhe todos os dias — dois gestos por visita.'
        );
    }

    /**
     * O nadador-salvador tem piscinas atribuídas: a instalação sai delas.
     */
    public function test_a_instalacao_vem_das_piscinas_atribuidas(): void
    {
        $ns = User::factory()->create();
        $ns->assignRole('nadador_salvador');
        $ns->piscinas()->attach($this->competicao->id);

        $this->assertSame($this->leiria->id, $this->instalacaoEscolhida($ns));
    }

    /**
     * Um técnico novo, sem registos nem piscinas: cai numa instalação ativa em
     * vez de ficar com o campo vazio.
     */
    public function test_um_tecnico_sem_historico_ainda_assim_recebe_uma_instalacao(): void
    {
        $this->assertNotNull(
            $this->instalacaoEscolhida($this->tecnico()),
            'Um campo obrigatório vazio à entrada é um gesto que se pode poupar sempre.'
        );
    }

    /**
     * O deep-link por piscina fixa a instalação dessa piscina, mesmo que o
     * histórico do técnico aponte para outra. É o que faz os atalhos do
     * dashboard valerem um só toque.
     */
    public function test_o_atalho_por_piscina_manda_na_instalacao(): void
    {
        $tecnico = $this->tecnico();
        $maceiraPool = Pool::where('installation_id', $this->maceira->id)->first();

        DailyRecord::create([
            'pool_id' => $this->competicao->id,
            'user_id' => $tecnico->id,
            'registado_em' => now()->subDay(),
            'ns_ph' => 7.4,
        ]);

        $estado = Livewire::actingAs($tecnico)
            ->withQueryParams(['pool' => $maceiraPool->id])
            ->test(CreateDailyRecord::class)
            ->get('data');

        $this->assertSame(
            $this->maceira->id,
            (int) $estado['installation_id'],
            'O atalho tem de ganhar ao histórico, senão levava o técnico à instalação errada.'
        );
    }

    /**
     * Guarda o atalho que o dashboard usa: se a rota ou o parâmetro mudarem de
     * nome, o botão do cartão da piscina deixa de pré-selecionar nada e a
     * entrada volta a custar quatro gestos.
     */
    public function test_a_url_do_atalho_leva_a_piscina(): void
    {
        $url = DailyRecordResource::getUrl('create', ['pool' => $this->competicao->id, 'quick' => 1]);

        $this->assertStringContainsString('pool='.$this->competicao->id, $url);
        $this->assertStringContainsString('quick=1', $url);
    }
}
