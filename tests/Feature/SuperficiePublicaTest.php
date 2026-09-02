<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guarda a superfície alcançável sem sessão.
 *
 * A casca protótipo /m e o seu endpoint /api/pdf/export serviam conteúdo a
 * qualquer pessoa sem autenticação. Foram removidos; este teste impede que
 * voltem sem que alguém repare.
 */
class SuperficiePublicaTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_de_pdf_falso_ja_nao_existe(): void
    {
        $this->get('/api/pdf/export')->assertNotFound();
        $this->get('/api/pdf/export?period=atual')->assertNotFound();
    }

    public function test_casca_mobile_ja_nao_existe(): void
    {
        foreach (['/m', '/m/diario', '/m/analise', '/m/incidentes/novo', '/m/exportar'] as $rota) {
            $this->get($rota)->assertNotFound();
        }
    }

    public function test_painel_exige_sessao(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    /**
     * O manifest é o que a app instalada abre. Se apontar para uma rota
     * sem autenticação, a app instalada abre uma porta aberta.
     */
    public function test_manifest_arranca_no_painel(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true);

        $this->assertSame('/admin', $manifest['start_url']);
    }
}
