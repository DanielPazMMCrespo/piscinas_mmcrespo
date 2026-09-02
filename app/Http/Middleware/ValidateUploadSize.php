<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateUploadSize
{
    // Este middleware é global (bootstrap/app.php) — corre em todos os POST/PUT
    // multipart, incluindo o próprio /livewire/upload-file. Um limite fixo aqui
    // é um segundo número a par de upload_max_filesize/post_max_size (php.ini)
    // e do ->maxSize() de cada campo (20480 KB nas fotos, 61440 KB no vídeo de
    // evidência da paragem técnica — TrabalhosRelationManager::MAX_VIDEO_KB).
    // Já divergiram uma vez: isto ficou parado em 20MB depois de o vídeo subir
    // para 60MB na sessão 26, e o clipe real era recusado aqui antes de chegar
    // à validação (com mensagem) do próprio campo. Derivar do PHP evita reabrir
    // a mesma divergência da próxima vez que um campo precisar de mais espaço —
    // só se soma um valor de segurança para a margem HEIC/multipart, não se
    // volta a hardcodar um segundo teto.
    private const MARGEM_BYTES = 1048576; // 1MB de folga para overhead do multipart/boundary

    public function handle(Request $request, Closure $next): Response
    {
        if (($request->isMethod('POST') || $request->isMethod('PUT')) && $this->isMultipartFormData($request)) {
            $contentLength = (int) $request->header('Content-Length', 0);
            $limiteBytes = $this->limiteBytes();

            if ($contentLength > $limiteBytes) {
                return response()->json([
                    'message' => 'O ficheiro é demasiado grande. Máximo: '.$this->limiteMb().'MB.',
                    'status' => 'error',
                ], 413);
            }
        }

        return $next($request);
    }

    private function isMultipartFormData(Request $request): bool
    {
        $contentType = (string) $request->header('Content-Type');

        return str_contains($contentType, 'multipart/form-data');
    }

    /**
     * O corpo real do pedido nunca passa nem de upload_max_filesize nem de
     * post_max_size — o PHP corta antes. O menor dos dois é o teto efetivo.
     */
    private function limiteBytes(): int
    {
        $uploadMax = $this->iniParaBytes($this->valorIni('upload_max_filesize'));
        $postMax = $this->iniParaBytes($this->valorIni('post_max_size'));

        return min($uploadMax, $postMax) + self::MARGEM_BYTES;
    }

    // `upload_max_filesize`/`post_max_size` são PHP_INI_PERDIR — não dá para
    // simular valores diferentes com ini_set() num teste. Isolado num método
    // `protected` só para um teste poder sobrepor com uma subclasse anónima,
    // sem inventar um segundo número que a produção nunca usaria.
    protected function valorIni(string $chave): string
    {
        return (string) ini_get($chave);
    }

    private function limiteMb(): int
    {
        return (int) round($this->limiteBytes() / 1048576);
    }

    private function iniParaBytes(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '' || $valor === '0') {
            return PHP_INT_MAX;
        }

        $unidade = strtolower(substr($valor, -1));
        $numero = (int) $valor;

        return match ($unidade) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }
}
