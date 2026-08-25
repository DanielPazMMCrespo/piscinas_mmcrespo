<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\TrabalhoParagem;
use App\Constants\UserRole;
use App\Filament\Pages\EncerramentoPiscinas;
use App\Filament\Resources\PoolClosureResource;
use App\Filament\Resources\PoolClosureResource\Pages\EditPoolClosure;
use App\Filament\Resources\PoolClosureResource\RelationManagers\TrabalhosRelationManager;
use App\Filament\Widgets\HistoricoEncerramentosWidget;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\User;
use App\Services\PlanoParagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PoolClosureRelationManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (UserRole::all() as $cargo) {
            Role::findOrCreate($cargo);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function criarUser(string $cargo): User
    {
        $user = User::factory()->create();
        $user->assignRole($cargo);

        return $user;
    }

    public function test_regista_trabalhos_relation_manager_em_pool_closure_resource_e_renderiza_pagina_de_edicao(): void
    {
        $admin = $this->criarUser(UserRole::ADMIN);
        $this->actingAs($admin);

        $this->assertContains(TrabalhosRelationManager::class, PoolClosureResource::getRelations());

        $closure = PoolClosure::factory()->create();

        Livewire::test(EditPoolClosure::class, ['record' => $closure->getRouteKey()])
            ->assertSuccessful();
    }

    public function test_permite_gerar_plano_de_trabalhos_de_13_tarefas_pelo_relation_manager(): void
    {
        $admin = $this->criarUser(UserRole::ADMIN);
        $this->actingAs($admin);

        $closure = PoolClosure::factory()->create();

        $this->assertSame(0, $closure->trabalhos()->count());

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->callTableAction('gerarPlano')
            ->assertHasNoTableActionErrors();

        $this->assertSame(13, $closure->trabalhos()->count());
    }

    public function test_permite_acrescentar_trabalho_avulso_pelo_relation_manager(): void
    {
        $admin = $this->criarUser(UserRole::ADMIN);
        $this->actingAs($admin);

        $closure = PoolClosure::factory()->create();
        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->callTableAction('acrescentarTrabalho', data: [
                'tipo' => TrabalhoParagem::OUTRO,
                'previsto_para' => '2026-08-20',
                'obrigatorio' => false,
                'observacoes' => 'Pintura geral da estrutura',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(14, $closure->trabalhos()->count());
        /** @var PoolClosureTask $ultimo */
        $ultimo = $closure->trabalhos()->reorder()->orderByDesc('ordem')->first();
        $this->assertSame('Pintura geral da estrutura', $ultimo->observacoes);
    }

    public function test_permite_executar_trabalho_com_validacao_de_legionella_pelo_relation_manager(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());
        $file = UploadedFile::fake()->create('boletim.pdf', 100, 'application/pdf');

        $tecnico = $this->criarUser(UserRole::TECNICO);
        $this->actingAs($tecnico);

        $closure = PoolClosure::factory()->create();
        app(PlanoParagemService::class)->criarPlano($closure, $tecnico);

        /** @var PoolClosureTask $legionella */
        $legionella = $closure->trabalhos()->where('tipo', TrabalhoParagem::DESINFECAO_LEGIONELLA)->first();

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->callTableAction('marcarExecutado', $legionella, data: [
                'executado_em' => '2026-08-10 10:00:00',
                'documentos' => [$file],
                'dados' => [
                    'laboratorio' => 'Laboratório Nacional',
                    'numero_boletim' => 'BOL-2026-001',
                    'resultado_legionella' => '< 100 UFC/L',
                ],
            ])
            ->assertHasNoTableActionErrors();

        $legionella->refresh();
        $this->assertSame(TrabalhoParagem::ESTADO_EXECUTADO, $legionella->estado);
        $this->assertSame($tecnico->id, $legionella->executado_por);
        $this->assertNotEmpty($legionella->documentos);
    }

    public function test_permite_marcar_trabalho_como_nao_aplicavel_com_justificacao(): void
    {
        $tecnico = $this->criarUser(UserRole::TECNICO);
        $this->actingAs($tecnico);

        $closure = PoolClosure::factory()->create();
        app(PlanoParagemService::class)->criarPlano($closure, $tecnico);

        /** @var PoolClosureTask $tanqueCompensacao */
        $tanqueCompensacao = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO)->first();

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->callTableAction('marcarNaoAplicavel', $tanqueCompensacao, data: [
                'motivo_nao_execucao' => 'Instalação de skimmers sem tanque de compensação',
            ])
            ->assertHasNoTableActionErrors();

        $tanqueCompensacao->refresh();
        $this->assertSame(TrabalhoParagem::ESTADO_NAO_APLICAVEL, $tanqueCompensacao->estado);
        $this->assertSame('Instalação de skimmers sem tanque de compensação', $tanqueCompensacao->motivo_nao_execucao);
    }

    public function test_mostra_acao_plano_de_paragem_na_pagina_encerramento_piscinas_e_no_historico(): void
    {
        $admin = $this->criarUser(UserRole::ADMIN);
        $this->actingAs($admin);

        $piscina = Pool::factory()->create();
        $closure = PoolClosure::factory()->create([
            'pool_id' => $piscina->id,
            'inicio' => Carbon::now()->subDays(2),
            'fim' => null,
        ]);

        Livewire::test(EncerramentoPiscinas::class)
            ->assertTableActionVisible('plano', $piscina);

        Livewire::test(HistoricoEncerramentosWidget::class)
            ->assertTableActionVisible('plano', $closure)
            ->assertTableActionVisible('editar', $closure);
    }
}
