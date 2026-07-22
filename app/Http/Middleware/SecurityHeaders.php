<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Append global security headers to every response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::cspNonce() ?: Str::random(32);
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $allowedImageDomains = implode(' ', [
            "'self'",
            'data:',
            'blob:',
            'https://*.r2.dev',
            'https://piscinasmmcrespo.up.railway.app',
            'https://piscinasmmcrespo-testes.up.railway.app',
            'https://piscinas-mmcrespo-main.up.railway.app',
        ]);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.bunny.net https://fonts.googleapis.com",
            "img-src {$allowedImageDomains}",
            "font-src 'self' data: https://fonts.bunny.net https://fonts.gstatic.com",
            "connect-src 'self' https://*.r2.dev",
            "worker-src 'self' blob:",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
        ]);

        $cspReportOnly = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'nonce-{$nonce}' https://fonts.bunny.net https://fonts.googleapis.com",
            "img-src {$allowedImageDomains}",
            "font-src 'self' data: https://fonts.bunny.net https://fonts.gstatic.com",
            "connect-src 'self' https://*.r2.dev",
            "worker-src 'self' blob:",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('Content-Security-Policy-Report-Only', $cspReportOnly);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        if ($request->isSecure() && ! app()->environment('local')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()');
        $response->headers->set('X-DNS-Prefetch-Control', 'off');

        return $response;
    }
}
