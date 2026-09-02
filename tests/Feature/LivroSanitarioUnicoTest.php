<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\RelatorioPdf;
use App\Models\Installation;
use App\Models\Pool;
use App\Services\DgsPdfReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prova de que o livro sanitário oficial (RelatorioPdf + pdf/livro-sanitario.blade.php)
 * é o único gerador do documento CN 14/DA — depois de App\Services\DgsPdfReportService
 * ter sido removido, dois geradores para o mesmo documento legal deixam de existir.
 *
 * Cobre também o termo de abertura/encerramento (Anexo III-b/c, CN 14/DA) portado do
 * gerador removido: só sai quando o relatório é de uma única piscina e de um mês
 * civil completo (RelatorioPdf::termoLegalElegivel).
 */
class LivroSanitarioUnicoTest extends TestCase
{
    use RefreshDatabase;

    private Installation $installation;

    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installation = Installation::create([
            'name' => 'Instalação de Teste',
            'morada' => 'Rua de Teste',
            'active' => true,
        ]);

        $this->pool = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Piscina de Teste',
            'type' => 'leisure',
            'temp_min' => 26.0,
            'temp_max' => 30.0,
            'volume' => 500.0,
            'active' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $seccoes
     * @param  array<int, string>  $seccoesVisiveis
     */
    private function renderLivro(array $seccoes, Carbon $inicio, Carbon $fim, array $seccoesVisiveis): string
    {
        return view('pdf.livro-sanitario', [
            'instalacao' => $this->installation,
            'seccoes' => $seccoes,
            'inicio' => $inicio,
            'fim' => $fim,
            'emitidoEm' => now(),
            'emitidoPor' => 'Teste',
            'colunasVisiveis' => ['ph', 'conforme'],
            'seccoesVisiveis' => $seccoesVisiveis,
            'modo' => 'todos',
        ])->render();
    }

    public function test_termo_aparece_para_uma_piscina_e_mes_civil_completo(): void
    {
        $inicio = Carbon::create(2026, 3, 1)->startOfDay();
        $fim = Carbon::create(2026, 3, 31)->endOfDay();

        $seccoes = [[
            'piscina' => $this->pool,
            'registos' => collect(),
            'controlador' => collect(),
        ]];

        $html = $this->renderLivro($seccoes, $inicio, $fim, ['mostrar_termo_legal']);

        $this->assertStringContainsString('Termo de Abertura', $html);
        $this->assertStringContainsString('Termo de Encerramento', $html);
        $this->assertStringContainsString('Delegado de Saúde', $html);
        $this->assertStringContainsString('Data de abertura: <strong>01/03/2026</strong>', $html);
        $this->assertStringContainsString('Data de encerramento: <strong>31/03/2026</strong>', $html);
        $this->assertStringNotContainsString('não impresso', $html);
    }

    public function test_termo_nao_aparece_para_periodo_que_nao_e_mes_completo(): void
    {
        $inicio = Carbon::create(2026, 3, 5)->startOfDay();
        $fim = Carbon::create(2026, 3, 15)->endOfDay();

        $seccoes = [[
            'piscina' => $this->pool,
            'registos' => collect(),
            'controlador' => collect(),
        ]];

        $html = $this->renderLivro($seccoes, $inicio, $fim, ['mostrar_termo_legal']);

        $this->assertStringNotContainsString('Termo de Abertura', $html);
        $this->assertStringNotContainsString('Termo de Encerramento', $html);
        $this->assertStringContainsString('não impresso', $html);
    }

    public function test_termo_nao_aparece_com_mais_do_que_uma_piscina(): void
    {
        $outraPiscina = Pool::create([
            'installation_id' => $this->installation->id,
            'name' => 'Outra Piscina',
            'type' => 'leisure',
            'temp_min' => 26.0,
            'temp_max' => 30.0,
            'volume' => 300.0,
            'active' => true,
        ]);

        $inicio = Carbon::create(2026, 3, 1)->startOfDay();
        $fim = Carbon::create(2026, 3, 31)->endOfDay();

        $seccoes = [
            ['piscina' => $this->pool, 'registos' => collect(), 'controlador' => collect()],
            ['piscina' => $outraPiscina, 'registos' => collect(), 'controlador' => collect()],
        ];

        $html = $this->renderLivro($seccoes, $inicio, $fim, ['mostrar_termo_legal']);

        $this->assertStringNotContainsString('Termo de Abertura', $html);
        $this->assertStringContainsString('não impresso', $html);
    }

    public function test_termo_nao_aparece_quando_nao_pedido(): void
    {
        $inicio = Carbon::create(2026, 3, 1)->startOfDay();
        $fim = Carbon::create(2026, 3, 31)->endOfDay();

        $seccoes = [[
            'piscina' => $this->pool,
            'registos' => collect(),
            'controlador' => collect(),
        ]];

        // Checkbox não marcada: nem termo, nem o aviso de omissão (que só faz
        // sentido quando o utilizador pediu o termo e não o vai receber).
        $html = $this->renderLivro($seccoes, $inicio, $fim, ['mostrar_resumo']);

        $this->assertStringNotContainsString('Termo de Abertura', $html);
        $this->assertStringNotContainsString('não impresso', $html);
    }

    public function test_termo_legal_elegivel_helper(): void
    {
        $inicio = Carbon::create(2026, 3, 1)->startOfDay();
        $fim = Carbon::create(2026, 3, 31)->endOfDay();

        $this->assertTrue(RelatorioPdf::termoLegalElegivel(1, $inicio, $fim));
        $this->assertFalse(RelatorioPdf::termoLegalElegivel(2, $inicio, $fim));
        $this->assertFalse(RelatorioPdf::termoLegalElegivel(1, $inicio, $fim->copy()->subDay()));
    }

    public function test_dgs_pdf_report_service_foi_removido(): void
    {
        $this->assertFalse(class_exists(DgsPdfReportService::class));
    }
}
