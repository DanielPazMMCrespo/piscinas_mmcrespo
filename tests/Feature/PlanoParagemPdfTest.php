<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\TrabalhoParagem;
use App\Constants\UserRole;
use App\Filament\Resources\PoolClosureResource\Pages\EditPoolClosure;
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

class PlanoParagemPdfTest extends TestCase
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

    public function test_gerar_plano_pdf_produz_documento_valido(): void
    {
        $admin = $this->criarUser(UserRole::ADMIN);
        $closure = PoolClosure::factory()->create([
            'inicio' => Carbon::parse('2026-08-10'),
            'fim' => Carbon::parse('2026-08-25'),
        ]);

        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        /** @var PlanoParagemPdfService $service */
        $service = app(PlanoParagemPdfService::class);
        $pdf = $service->gerarPlanoPdf($closure, $admin);

        $output = $pdf->output();
        $this->assertNotNull($output);
        $this->assertStringStartsWith('%PDF-', (string) $output);
    }

    public function test_gerar_relatorio_pdf_com_grafico_sonda_anexos_e_anexo_a(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());

        $admin = $this->criarUser(UserRole::ADMIN);
        $piscina = Pool::factory()->create([
            'orp_min' => 680,
            'orp_max' => 780,
            'temp_min' => 26.5,
            'temp_max' => 28.0,
        ]);

        $closure = PoolClosure::factory()->create([
            'pool_id' => $piscina->id,
            'inicio' => Carbon::parse('2026-08-10'),
            'fim' => Carbon::parse('2026-08-20'),
        ]);

        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        // Adicionar leituras de sensor no período
        for ($i = 0; $i < 48; $i++) {
            SensorReading::create([
                'pool_id' => $piscina->id,
                'hanna_device_id' => 'DEV-01',
                'lida_em' => Carbon::parse('2026-08-10 00:00:00')->addHours($i),
                'orp' => 720 + ($i % 10) * 5,
                'temperatura_agua' => 27.0 + ($i % 5) * 0.1,
            ]);
        }

        // Adicionar ficheiro de foto e boletim
        $disk = Storage::disk(DailyRecord::getStorageDisk());
        $fotoPath = 'paragens/tanque_limpo.jpg';
        $disk->put($fotoPath, 'fake-image-binary-data');

        $docPath = 'paragens/boletim_legionella.pdf';
        $disk->put($docPath, 'fake-pdf-content');

        /** @var PoolClosureTask $taskLimpeza */
        $taskLimpeza = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_TANQUE)->first();
        $taskLimpeza->update([
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'executado_em' => Carbon::parse('2026-08-11 10:00:00'),
            'executado_por' => $admin->id,
            'fotos' => [$fotoPath],
        ]);

        /** @var PoolClosureTask $taskLegionella */
        $taskLegionella = $closure->trabalhos()->where('tipo', TrabalhoParagem::DESINFECAO_LEGIONELLA)->first();
        $taskLegionella->update([
            'estado' => TrabalhoParagem::ESTADO_EXECUTADO,
            'executado_em' => Carbon::parse('2026-08-12 14:00:00'),
            'executado_por' => $admin->id,
            'documentos' => [$docPath],
            'dados' => [
                'laboratorio' => 'Laboratório Central',
                'numero_boletim' => 'BOL-2026-999',
                'resultado_legionella' => '< 10 UFC/L (Não Detetado)',
            ],
        ]);

        // Ação operacional no período (Anexo A)
        OperationalAction::create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'tipo' => 'tratamento_choque',
            'registado_em' => Carbon::parse('2026-08-13 18:00:00'),
            'dados' => ['produto' => 'Hipoclorito de Cálcio', 'quantidade' => 15.0],
            'observacoes' => 'Tratamento de choque prévio ao enchimento',
        ]);

        // Ação operacional fora do período (não deve aparecer)
        OperationalAction::create([
            'pool_id' => $piscina->id,
            'user_id' => $admin->id,
            'tipo' => 'lavagem_filtro',
            'registado_em' => Carbon::parse('2026-08-01 08:00:00'),
            'observacoes' => 'Lavagem anterior',
        ]);

        /** @var PlanoParagemPdfService $service */
        $service = app(PlanoParagemPdfService::class);
        $pdf = $service->gerarRelatorioPdf($closure, $admin);

        $output = $pdf->output();
        $this->assertNotNull($output);
        $this->assertStringStartsWith('%PDF-', (string) $output);

        // Testar dados da blade
        $dados = $service->prepararDadosRelatorio($closure, $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $this->assertStringContainsString('Laboratório Central', $html);
        $this->assertStringContainsString('BOL-2026-999', $html);
        $this->assertStringContainsString('Tratamento de choque prévio ao enchimento', $html);
        $this->assertStringNotContainsString('Lavagem anterior', $html);
        $this->assertStringContainsString('data:image/jpeg;base64,', $html);
    }

    public function test_video_de_evidencia_e_referenciado_no_relatorio_sem_ser_embutido(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());

        $admin = $this->criarUser(UserRole::ADMIN);
        $closure = PoolClosure::factory()->create([
            'inicio' => Carbon::parse('2026-08-10'),
            'fim' => Carbon::parse('2026-08-20'),
        ]);

        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        $disk = Storage::disk(DailyRecord::getStorageDisk());
        $videoPath = 'paragens/videos/tanque_limpo.mp4';
        $conteudo = 'fake-video-binary-data';
        $disk->put($videoPath, $conteudo);

        /** @var PoolClosureTask $tarefa */
        $tarefa = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_TANQUE)->first();

        app(PlanoParagemService::class)->marcarExecutado($tarefa, [
            'executado_em' => Carbon::parse('2026-08-11 10:00:00'),
            'videos' => [$videoPath],
        ], $admin);

        $this->assertSame([$videoPath], $tarefa->fresh()->videos);

        /** @var PlanoParagemPdfService $service */
        $service = app(PlanoParagemPdfService::class);
        $dados = $service->prepararDadosRelatorio($closure, $admin);
        $html = view('pdf.paragem.relatorio', $dados)->render();

        $this->assertStringContainsString('Registo em vídeo da intervenção', $html);
        $this->assertStringContainsString('tanque_limpo.mp4', $html);
        // O video nunca e embutido: o dompdf nao o reproduz.
        $this->assertStringNotContainsString('data:video/', $html);
        // Hash do video fora do documento, por decisao do responsavel tecnico.
        $this->assertStringNotContainsString(substr(hash('sha256', $conteudo), 0, 16), $html);
    }

    public function test_header_actions_de_download_em_edit_pool_closure(): void
    {
        $admin = $this->criarUser(UserRole::ADMIN);
        $this->actingAs($admin);

        $closure = PoolClosure::factory()->create();
        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        Livewire::test(EditPoolClosure::class, ['record' => $closure->getRouteKey()])
            ->callAction('descarregarPlano')
            ->assertHasNoActionErrors();

        Livewire::test(EditPoolClosure::class, ['record' => $closure->getRouteKey()])
            ->callAction('descarregarRelatorio')
            ->assertHasNoActionErrors();
    }
}
