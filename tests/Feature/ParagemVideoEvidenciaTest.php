<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\TrabalhoParagem;
use App\Constants\UserRole;
use App\Filament\Resources\PoolClosureResource\Pages\EditPoolClosure;
use App\Filament\Resources\PoolClosureResource\RelationManagers\TrabalhosRelationManager;
use App\Models\DailyRecord;
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

/**
 * Percurso completo do video de evidencia: upload real -> R2 -> visualizacao.
 * O ficheiro em tests/Fixtures e um MP4 verdadeiro truncado, para as regras
 * `mimetypes` do Laravel verem o mesmo cabecalho que veriam num telemovel.
 */
class ParagemVideoEvidenciaTest extends TestCase
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
     * Ficheiro de teste com os bytes verdadeiros de um MP4: e o cabecalho real
     * que faz a regra `mimetypes` decidir, tal como decidiria num telemovel.
     */
    private function videoReal(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'tanque_limpo.mp4',
            (string) file_get_contents(base_path('tests/Fixtures/video-evidencia.mp4')),
        );
    }

    /**
     * @return array{0: PoolClosure, 1: PoolClosureTask, 2: User}
     */
    private function cenario(): array
    {
        $admin = $this->admin();
        $closure = PoolClosure::factory()->create([
            'inicio' => Carbon::parse('2026-08-10'),
            'fim' => Carbon::parse('2026-08-20'),
        ]);

        app(PlanoParagemService::class)->criarPlano($closure, $admin);

        /** @var PoolClosureTask $tarefa */
        $tarefa = $closure->trabalhos()->where('tipo', TrabalhoParagem::LIMPEZA_TANQUE)->first();

        return [$closure, $tarefa, $admin];
    }

    public function test_upload_de_video_real_grava_ficheiro_e_caminho_na_tarefa(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());
        [$closure, $tarefa, $admin] = $this->cenario();
        $this->actingAs($admin);

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->callTableAction('marcarExecutado', $tarefa, data: [
                'executado_em' => '2026-08-11 10:00:00',
                'videos' => [$this->videoReal()],
            ])
            ->assertHasNoTableActionErrors();

        $tarefa->refresh();

        $this->assertSame(TrabalhoParagem::ESTADO_EXECUTADO, $tarefa->estado);
        $this->assertIsArray($tarefa->videos);
        $this->assertCount(1, $tarefa->videos);

        $caminho = $tarefa->videos[0];
        $this->assertStringStartsWith('paragens/videos/', $caminho);
        Storage::disk(DailyRecord::getStorageDisk())->assertExists($caminho);

        // O que ficou no disco tem de ser o video, byte a byte.
        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/video-evidencia.mp4')),
            Storage::disk(DailyRecord::getStorageDisk())->get($caminho),
        );
    }

    public function test_upload_recusa_ficheiro_que_nao_e_video(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());
        [$closure, $tarefa, $admin] = $this->cenario();
        $this->actingAs($admin);

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->callTableAction('marcarExecutado', $tarefa, data: [
                'executado_em' => '2026-08-11 10:00:00',
                'videos' => [UploadedFile::fake()->create('boletim.pdf', 100, 'application/pdf')],
            ]);

        $this->assertEmpty($tarefa->fresh()->videos);
        $this->assertEmpty(Storage::disk(DailyRecord::getStorageDisk())->files('paragens/videos'));
    }

    public function test_upload_recusa_video_acima_do_limite_de_60_mb(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());
        [$closure, $tarefa, $admin] = $this->cenario();
        $this->actingAs($admin);

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->callTableAction('marcarExecutado', $tarefa, data: [
                'executado_em' => '2026-08-11 10:00:00',
                'videos' => [UploadedFile::fake()->create('longo.mp4', 61441, 'video/mp4')],
            ]);

        $this->assertEmpty($tarefa->fresh()->videos);
        $this->assertEmpty(Storage::disk(DailyRecord::getStorageDisk())->files('paragens/videos'));
    }

    public function test_accao_evidencias_so_aparece_quando_ha_video_ou_foto(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());
        [$closure, $tarefa, $admin] = $this->cenario();
        $this->actingAs($admin);

        $componente = Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ]);

        $componente->assertTableActionHidden('verEvidencias', $tarefa);

        $tarefa->update(['videos' => ['paragens/videos/tanque_limpo.mp4']]);

        Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])->assertTableActionVisible('verEvidencias', $tarefa->fresh());
    }

    public function test_campo_de_video_avisa_para_gravar_em_1080p(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());
        [$closure, $tarefa, $admin] = $this->cenario();
        $this->actingAs($admin);

        $html = Livewire::test(TrabalhosRelationManager::class, [
            'ownerRecord' => $closure,
            'pageClass' => EditPoolClosure::class,
        ])
            ->mountTableAction('marcarExecutado', $tarefa)
            ->assertHasNoTableActionErrors()
            ->html();

        // Em 4K um clipe de 15 s passa dos 60 MB e e recusado no upload. O
        // aviso e a unica coisa que evita a filmagem perdida — se sair do
        // formulario, este teste tem de rebentar.
        $this->assertStringContainsString('Grave em 1080p, nao em 4K', str_replace('ã', 'a', $html));
        $this->assertStringContainsString('1080p HD, 30 fps', $html);
        $this->assertStringContainsString('Mais Compat', $html);
    }

    public function test_modal_de_evidencias_reproduz_o_video_e_oferece_descarga(): void
    {
        Storage::fake(DailyRecord::getStorageDisk());
        [$closure, $tarefa, $admin] = $this->cenario();
        $this->actingAs($admin);

        $caminho = 'paragens/videos/tanque_limpo.mp4';
        Storage::disk(DailyRecord::getStorageDisk())->put($caminho, 'conteudo');
        $tarefa->update(['videos' => [$caminho]]);

        $html = view('filament.paragem.evidencias', ['tarefa' => $tarefa->fresh()])->render();

        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('controls', $html);
        $this->assertStringContainsString(DailyRecord::getStorageUrl($caminho), $html);
        // Um clipe HEVC de iPhone nao toca no Chrome do Windows: a descarga
        // e a saida que tem de existir sempre.
        $this->assertStringContainsString('Descarregar tanque_limpo.mp4', $html);
    }
}
