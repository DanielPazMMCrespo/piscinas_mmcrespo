<?php declare(strict_types=1);
namespace App\Http\Middleware;

namespace App\Http\Middleware;

use App\Services\MetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetricsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip metrics collection for health/metrics endpoints
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        if (!config('prometheus.enabled')) {
            return $next($request);
        }

        $startTime = microtime(true);

        $response = $next($request);

        // Record metrics
        $durationMs = (int) ((microtime(true) - $startTime) * 1000);
        $route = $request->route()?->getName() ?? $request->getPath();

        MetricsService::recordRequestLatency(
            $route,
            $durationMs,
            $response->getStatusCode()
        );

        return $response;
    }

    /**
     * Check if this request should skip metrics collection.
     */
    private function shouldSkip(Request $request): bool
    {
        $skipPaths = ['/api/health', '/api/metrics', '/up'];

        foreach ($skipPaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
