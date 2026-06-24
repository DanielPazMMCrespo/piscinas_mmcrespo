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
        if (($request->isMethod('POST') || $request->isMethod('PUT')) && $this->isMultipartFormData($request)) {
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

    private function isMultipartFormData(Request $request): bool
    {
        $contentType = (string) $request->header('Content-Type');
        return str_contains($contentType, 'multipart/form-data');
    }
}
