<?php declare(strict_types=1);
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateUploadSize
{
    // 20MB, alinhado com ->maxSize(20480) nos FileUpload de fotos (DailyRecordFormBuilder,
    // OperationalActionResource) — mobile/iPhone HEIC precisa desta margem.
    private const MAX_UPLOAD_BYTES = 20971520;

    public function handle(Request $request, Closure $next): Response
    {
        if (($request->isMethod('POST') || $request->isMethod('PUT')) && $this->isMultipartFormData($request)) {
            $contentLength = (int) $request->header('Content-Length', 0);

            if ($contentLength > self::MAX_UPLOAD_BYTES) {
                return response()->json([
                    'message' => 'O ficheiro é demasiado grande. Máximo: 20MB.',
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
