<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ValidateUploadSize;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * O vídeo de evidência da paragem técnica (sessão 26) sobe o teto do campo
 * para 60MB (`TrabalhosRelationManager::MAX_VIDEO_KB`), mas o middleware
 * global `ValidateUploadSize` continuava fixo em ~20MB — um clipe real de
 * 15-30s a 1080p (25-50MB) era recusado aqui, antes de sequer chegar à
 * validação do próprio campo. Testa o middleware diretamente: não se cria
 * um ficheiro de 60MB a sério, só um pedido com o `Content-Length` que um
 * upload desse tamanho teria.
 */
class UploadTamanhoVideoTest extends TestCase
{
    private const UM_MB = 1048576;

    private function pedidoComContentLength(int $bytes): Request
    {
        $request = Request::create('/livewire/upload-file', 'POST');
        $request->headers->set('Content-Type', 'multipart/form-data; boundary=X');
        $request->headers->set('Content-Length', (string) $bytes);

        return $request;
    }

    /**
     * `upload_max_filesize`/`post_max_size` são PHP_INI_PERDIR — não dá para
     * mudar com ini_set() a meio do teste. Usa a mesma subclasse anónima nos
     * dois testes, fixando os valores de produção (Dockerfile/public/.user.ini:
     * 64M / 128M) em vez de depender do php.ini de quem corre a suite.
     */
    private function middlewareComIniDeProducao(): ValidateUploadSize
    {
        return new class extends ValidateUploadSize
        {
            protected function valorIni(string $chave): string
            {
                return match ($chave) {
                    'upload_max_filesize' => '64M',
                    'post_max_size' => '128M',
                    default => '',
                };
            }
        };
    }

    public function test_video_de_evidencia_perto_do_limite_de_60mb_passa(): void
    {
        // TrabalhosRelationManager::MAX_VIDEO_KB = 61440 KB (60MB), com margem.
        $tamanhoVideo = (61440 * 1024) - self::UM_MB;

        $response = $this->middlewareComIniDeProducao()->handle(
            $this->pedidoComContentLength($tamanhoVideo),
            fn (Request $request): Response => new Response('ok', 200),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_upload_muito_acima_do_limite_e_recusado_com_mensagem_clara(): void
    {
        $response = $this->middlewareComIniDeProducao()->handle(
            $this->pedidoComContentLength(200 * self::UM_MB),
            fn (Request $request): Response => new Response('ok', 200),
        );

        $this->assertSame(413, $response->getStatusCode());

        $body = json_decode($response->getContent(), true);
        $this->assertStringContainsString('demasiado grande', $body['message']);
        $this->assertStringContainsString('MB', $body['message']);
    }
}
