<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\User;
use App\Services\DailyRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Um nadador-salvador cujas piscinas estão TODAS encerradas com a água parada
 * fica com o formulário sem cartões de piscina. Até à sessão em que o ecrã
 * bloqueador foi removido (BlockClosedPoolAccess), o middleware desviava-o para
 * /piscinas-encerradas e este caminho nunca era alcançado. Sem o middleware, a
 * submissão chegava ao DailyRecordService com `pools` vazio, o serviço devolvia
 * null e a página convertia isso num RuntimeException — erro 500 no telemóvel
 * do NS, sem nenhuma pista do motivo.
 */
class DailyRecordSemPiscinasDisponiveisTest extends TestCase
{
    use RefreshDatabase;

    private User $ns;

    private Pool $competicao;

    private Pool $lazer;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->ns = User::factory()->create();
        $this->ns->assignRole('nadador_salvador');

        $leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $leiria->id,
            'name' => 'Competicao',
            'volume' => 900,
        ]);
        $this->lazer = Pool::factory()->create([
            'installation_id' => $leiria->id,
            'name' => 'Lazer',
            'volume' => 600,
        ]);

        $this->ns->piscinas()->attach([$this->competicao->id, $this->lazer->id]);
    }

    private function pararPiscinas(): void
    {
        foreach ([$this->competicao, $this->lazer] as $piscina) {
            PoolClosure::create([
                'pool_id' => $piscina->id,
                'inicio' => now()->subDays(3),
                'fim' => null,
                'motivo' => 'manutencao',
                'agua_em_tratamento' => false,
            ]);
        }
    }

    public function test_submeter_sem_piscinas_da_erro_de_validacao_e_nao_500(): void
    {
        $this->pararPiscinas();

        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->set('data.ns_foto', ['guardado' => 'ns-fotos/quadro.jpg'])
            ->call('validarERegistosGuardar');

        $this->assertDatabaseCount('daily_records', 0);
    }

    public function test_o_botao_gravar_desaparece_quando_nao_ha_piscinas(): void
    {
        $this->pararPiscinas();

        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->assertActionHidden('create');
    }

    public function test_o_servico_recusa_payload_sem_piscinas(): void
    {
        $this->expectException(ValidationException::class);

        app(DailyRecordService::class)->createRecords($this->ns, [
            'registado_em' => now()->toDateString(),
            'pools' => [],
        ]);
    }

    public function test_com_piscinas_disponiveis_o_ns_grava_normalmente(): void
    {
        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->set('data.ns_foto', ['guardado' => 'ns-fotos/quadro.jpg'])
            ->set("data.pools.{$this->competicao->id}.ns_ph", 7.2)
            ->set("data.pools.{$this->competicao->id}.ns_cloro_livre", 1.0)
            ->set("data.pools.{$this->competicao->id}.ns_cloro_total", 1.2)
            ->set("data.pools.{$this->competicao->id}.ns_temperatura", 27)
            ->set("data.pools.{$this->lazer->id}.ns_ph", 7.2)
            ->set("data.pools.{$this->lazer->id}.ns_cloro_livre", 1.0)
            ->set("data.pools.{$this->lazer->id}.ns_cloro_total", 1.2)
            ->set("data.pools.{$this->lazer->id}.ns_temperatura", 29)
            ->call('validarERegistosGuardar')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('daily_records', 2);
    }

    public function test_ns_sem_piscinas_atribuidas_nesta_instalacao_mostra_mensagem_especifica(): void
    {
        $outraInstalacao = Installation::factory()->create(['name' => 'Maceira']);
        $piscinaMaceira = Pool::factory()->create(['installation_id' => $outraInstalacao->id, 'name' => 'Maceira']);
        $this->ns->piscinas()->sync([$piscinaMaceira->id]);

        Livewire::actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->set('data.installation_id', $this->competicao->installation_id)
            ->assertSee('Não tem piscinas atribuídas nesta instalação');
    }

    public function test_ns_com_quick_e_pool_na_query_continua_a_ver_todas_as_suas_piscinas(): void
    {
        Livewire::withQueryParams(['pool' => $this->competicao->id, 'quick' => '1'])
            ->actingAs($this->ns)
            ->test(CreateDailyRecord::class)
            ->assertSee('Competicao')
            ->assertSee('Lazer');
    }
}
