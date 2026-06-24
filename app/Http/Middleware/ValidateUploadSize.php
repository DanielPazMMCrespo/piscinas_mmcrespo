<?php declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateUploadSize
{
    private const MAX_UPLOAD_BYTES = 5242880; // 5MB em bytes (5120 KB)

    public function handle(Request $request, Closure $next): Response
    {
        // Apenas valida uploads (POST/PUT multipart)
        if ($request->isMethod(['post', 'put']) && $request->isMultipart()) {
            $contentLength = (int) $request->header('Content-Length', 0);

            if ($contentLength > self::MAX_UPLOAD_BYTES) {
                return response()->json([
                    'message' => 'O ficheiro é demasiado grande. Máximo: 5MB.',
                    'status' => 'error',
                ], 413);
            }
        }

        return $next($request);
    }
}
