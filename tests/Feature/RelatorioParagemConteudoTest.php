<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\TrabalhoParagem;
use App\Constants\UserRole;
use App\Filament\Resources\PoolClosureResource\Pages\EditPoolClosure;
use App\Filament\Resources\PoolClosureResource\RelationManagers\TrabalhosRelationManager;
use App\Models\DailyRecord;
use App\Models\OperationalAction;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\SensorReading;
use App\Models\User;
use App\Services\PlanoParagemPdfService;
use App\Services\PlanoParagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RelatorioParagemConteudoTest extends TestCase
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

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::ADMIN);

        return $user;
    }

    /**
     * @return array{0: PoolClosure, 1: User, 2: Pool}
     */
    private function cenario(): array
    {
        $admin = $this->admin();
        $piscina = Pool::factory()->create(['orp_min' => 680, 'orp_max' => 780]);

        $closure = PoolClosure::factory()->create([
            'pool_id' => $piscina->id,
            'inicio' => Carbon::parse('2026-08-10'),
            'fim' => Carbon::parse('2026-08-20'),
        ]);

        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        return [$closure, $admin, $piscina];
    }

    public function test_anexo_a_nao_lista_reabastecimento_de_bidao(): void
    {
        [$closure, $admin, $piscina] = $this->cenario();

        OperationalAction::create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_REABASTECIMENTO_BIDAO,
            'registado_em' => Carbon::parse('2026-08-12 11:00:00'),
            'observacoes' => 'Enchimento do bidao de cloro',
        ]);

        OperationalAction::create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_LAVAGEM_FILTRO,
            'registado_em' => Carbon::parse('2026-08-12 12:00:00'),
            'observacoes' => 'Lavagem prolongada do filtro',
        ]);

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure, $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        // Reabastecer um bidao e logistica de consumiveis, nao intervencao.
        $this->assertStringNotContainsString('Enchimento do bidao de cloro', $html);
        $this->assertStringNotContainsString('Reabastecimento de bidão', $html);
        $this->assertStringContainsString('Lavagem prolongada do filtro', $html);
    }

    public function test_tabela_de_trabalhos_nao_mostra_coluna_de_origem(): void
    {
        [$closure, $admin] = $this->cenario();

        /** @var PoolClosureTask $tarefa */
        $tarefa = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_TANQUE)->first();
        $tarefa->update([
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'origem' => TrabalhoParagem::ORIGEM_RECONSTRUIDA,
            'executado_em' => Carbon::parse('2026-08-11 10:00:00'),
            'executado_por' => $admin->id,
        ]);

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure, $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        // "Reconstruida" e vocabulario interno; nao pertence ao documento legal.
        $this->assertStringNotContainsString('Reconstruída', $html);
        $this->assertStringNotContainsString('>Origem<', $html);
        $this->assertStringContainsString('Limpeza e desinfeção do tanque', $html);
    }

    public function test_anexo_b_valida_o_orp_quando_ha_pares_suficientes(): void
    {
        [$closure, $admin, $piscina] = $this->cenario();

        // Quatro pares limpos: +100 mV por cada +1 mg/L.
        $pares = [[0.50, 650.0], [1.00, 700.0], [1.50, 750.0], [2.00, 800.0]];

        foreach ($pares as $i => [$cloro, $orp]) {
            $quando = Carbon::parse('2026-08-11 10:00:00')->addDays($i);

            DailyRecord::create([
                'pool_id' => $piscina->id,
                'user_id' => $admin->id,
                'registado_em' => $quando,
                'hora_colheita' => $quando->format('H:i'),
                'cloro_livre' => $cloro,
                'ph' => 7.40,
            ]);

            SensorReading::create([
                'pool_id' => $piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => $quando->copy()->addMinutes(5),
                'orp' => $orp,
            ]);
        }

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure, $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $this->assertTrue($dados['correlacaoOrp']['utilizavel']);
        $this->assertStringContainsString('Anexo B', $html);
        $this->assertStringContainsString('Correlação validada', $html);
        $this->assertStringContainsString('n = 4', $html);
        // A gama de pH tem de sair: a correlacao so vale dentro dela.
        $this->assertStringContainsString('com pH entre', $html);
    }

    public function test_anexo_b_declara_a_ausencia_de_validacao_em_vez_de_inventar(): void
    {
        [$closure, $admin] = $this->cenario();

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure, $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $this->assertFalse($dados['correlacaoOrp']['utilizavel']);
        $this->assertStringContainsString('Correlação não validada neste período', $html);
        $this->assertStringNotContainsString('Correlação validada.', $html);
    }

    public function test_eventos_de_sonda_saem_agregados_por_tipo_e_sem_temperaturas(): void
    {
        [$closure, $admin, $piscina] = $this->cenario();

        // Tres rampas de aquecimento em dias diferentes: +0.6 C/h durante 6 h.
        foreach (['2026-08-11', '2026-08-13', '2026-08-15'] as $dia) {
            for ($h = 0; $h <= 6; $h++) {
                SensorReading::create([
                    'pool_id' => $piscina->id,
                    'hanna_device_id' => 'DEV-AQ',
                    'lida_em' => Carbon::parse($dia)->setTime(6 + $h, 0),
                    'temperatura_agua' => 22.0 + ($h * 0.6),
                    'temperatura_ar' => 20.0,
                    'orp' => 700,
                ]);
            }
            // Arrefecimento nocturno, para as rampas nao se colarem umas as outras.
            for ($h = 0; $h <= 5; $h++) {
                SensorReading::create([
                    'pool_id' => $piscina->id,
                    'hanna_device_id' => 'DEV-AQ',
                    'lida_em' => Carbon::parse($dia)->setTime(14 + $h, 0),
                    'temperatura_agua' => 25.6 - ($h * 0.7),
                    'temperatura_ar' => 20.0,
                    'orp' => 700,
                ]);
            }
        }

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure, $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $aquecimento = collect($dados['eventosSonda'])
            ->firstWhere('tipo', TrabalhoParagem::ARRANQUE_AQUECIMENTO);

        $this->assertNotNull($aquecimento, 'As rampas de aquecimento deviam ter sido detetadas.');

        // Uma linha por tipo, nao uma por ocorrencia.
        $this->assertSame(
            1,
            collect($dados['eventosSonda'])->where('tipo', TrabalhoParagem::ARRANQUE_AQUECIMENTO)->count()
        );
        $this->assertGreaterThan(1, $aquecimento['ocorrencias']);
        $this->assertStringContainsString(
            $aquecimento['ocorrencias'].' rampas de subida sustentada',
            $html
        );

        // A amplitude por rampa mistura insolacao e deriva da sonda: nao se afirma
        // como sendo do aquecedor num documento legal.
        $this->assertStringNotContainsString('Subida térmica da água de', $html);
        $this->assertStringContainsString('rampas de subida sustentada da temperatura da água', $html);
    }

    public function test_declaracoes_do_responsavel_saem_separadas_da_prova_instrumental(): void
    {
        [$closure, $admin] = $this->cenario();

        $closure->update([
            'observacoes' => "A partir de 25/08 os filtros foram lavados diariamente.\nSegunda linha.",
        ]);

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure->fresh(), $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $this->assertStringContainsString('Anexo A.1 — Declarações do Responsável Técnico', $html);
        $this->assertStringContainsString('sem suporte instrumental automático', $html);
        $this->assertStringContainsString('os filtros foram lavados diariamente', $html);

        // O texto nao pode ficar espremido na celula de Observacoes do ponto 1.
        $this->assertStringContainsString('Ver Anexo A.1', $html);
        $this->assertSame(1, substr_count($html, 'os filtros foram lavados diariamente'));
    }

    public function test_video_sai_com_ligacao_clicavel_e_sem_hash(): void
    {
        [$closure, $admin] = $this->cenario();

        $disco = DailyRecord::getStorageDisk();
        Storage::fake($disco, ['url' => 'https://provas.example.test']);

        $caminho = 'paragens/videos/tanque-compensacao.mp4';
        Storage::disk($disco)->put($caminho, 'bytes-do-video');

        /** @var PoolClosureTask $tarefa */
        $tarefa = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO)->first();
        $tarefa->update([
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'executado_em' => Carbon::parse('2026-08-15 10:00:00'),
            'executado_por' => $admin->id,
            'videos' => [$caminho],
        ]);

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure->fresh(), $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $url = $dados['videosIndex'][0]['url'];

        // Um caminho relativo nao abre a partir de um PDF lido fora da app.
        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('<a href="'.$url.'"', $html);
        $this->assertStringNotContainsString('ligação indisponível', $html);

        // Hash fora do documento, por decisao do responsavel tecnico.
        $this->assertArrayNotHasKey('sha256', $dados['videosIndex'][0]);
        $this->assertStringNotContainsString('SHA-256:', $html);
        $this->assertStringNotContainsString(hash('sha256', 'bytes-do-video'), $html);
    }

    public function test_video_sem_url_absoluta_declara_a_falta_em_vez_de_a_esconder(): void
    {
        [$closure, $admin] = $this->cenario();

        $disco = DailyRecord::getStorageDisk();
        Storage::fake($disco, ['url' => null]);

        $caminho = 'paragens/videos/sem-dominio.mp4';
        Storage::disk($disco)->put($caminho, 'bytes-do-video');

        /** @var PoolClosureTask $tarefa */
        $tarefa = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO)->first();
        $tarefa->update([
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'executado_em' => Carbon::parse('2026-08-15 10:00:00'),
            'executado_por' => $admin->id,
            'videos' => [$caminho],
        ]);

        $dados = app(PlanoParagemPdfService::class)->prepararDadosRelatorio($closure->fresh(), $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $this->assertNull($dados['videosIndex'][0]['url']);
        $this->assertStringContainsString('ligação indisponível', $html);
        $this->assertStringContainsString('sem-dominio.mp4', $html);
    }

    public function test_sugerir_evidencia_nao_marca_executado_quando_nao_ha_evidencia(): void
    {
        [$closure, $admin] = $this->cenario();
        $this->actingAs($admin);

        /** @var PoolClosureTask $tarefa */
        $tarefa = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_CIRCUITO)->first();

        // Sem acoes operacionais nem leituras de sonda no periodo.
        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])->callTableAction('reconstruirEvidencia', $tarefa);

        $tarefa->refresh();

        // Antes, isto gravava "executado / reconstruido a partir de evidencia"
        // sem evidencia nenhuma, e era isso que saia no relatorio legal.
        $this->assertSame(TrabalhoParagem::ESTADO_PREVISTO, $tarefa->estado);
        $this->assertNull($tarefa->executado_em);
    }

    public function test_sugerir_evidencia_funciona_quando_ha_acao_operacional(): void
    {
        [$closure, $admin, $piscina] = $this->cenario();
        $this->actingAs($admin);

        $acao = OperationalAction::create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'tipo' => OperationalAction::TIPO_TRATAMENTO_CHOQUE,
            'registado_em' => Carbon::parse('2026-08-12 09:00:00'),
            'observacoes' => 'Hipercloracao do circuito',
        ]);

        /** @var PoolClosureTask $tarefa */
        $tarefa = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_CIRCUITO)->first();

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])->callTableAction('reconstruirEvidencia', $tarefa, data: [
            'evidencia_selecionada' => "acao_{$acao->id}",
            'executado_em' => '2026-08-12 09:00:00',
        ])->assertHasNoTableActionErrors();

        $tarefa->refresh();

        $this->assertSame(TrabalhoParagem::ESTADO_EXECUTADO, $tarefa->estado);
        $this->assertNotNull($tarefa->executado_em);
    }
}
