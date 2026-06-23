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
     *
     * CSP — estratégia em duas políticas:
     *  - ENFORCED (Content-Security-Policy): mantém 'unsafe-inline'/'unsafe-eval' em
     *    script-src porque o Filament 3.2 + Alpine.js + Livewire emitem scripts inline
     *    SEM nonce e o Alpine avalia expressões dinâmicas (x-data) — removê-los parte o painel.
     *  - REPORT-ONLY (Content-Security-Policy-Report-Only): política nonce-based estrita,
     *    sem unsafe-inline/eval, que NÃO bloqueia nada mas reporta violações. É a 1ª fase
     *    da migração para nonce: recolher o que precisaria de nonce antes do switch enforced.
     *    O nonce por-pedido é partilhado com as views via Vite::useCspNonce() (ver AppServiceProvider).
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Nonce único por pedido. Vite::useCspNonce() (AppServiceProvider) gera-o e carimba-o
        // nos assets @vite; aqui lemos o mesmo valor. Fallback para pedidos fora do ciclo Vite.
        $nonce = Vite::cspNonce() ?: Str::random(32);
        $request->attributes->set('csp_nonce', $nonce);

        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https://*.r2.dev",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "base-uri 'self'",
        ]);

        // Política estrita em modo report-only — não bloqueia, apenas reporta o que partiria.
        $cspReportOnly = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'",
            "style-src 'self' 'nonce-{$nonce}'",
            "img-src 'self' data: blob: https://*.r2.dev",
            "font-src 'self' data:",
            "connect-src 'self'",
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
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        return $response;
    }
}
