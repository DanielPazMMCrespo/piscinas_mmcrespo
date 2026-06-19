<?php declare(strict_types=1);
namespace App\Http\Middleware;


use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate or retrieve request ID
        $requestId = $request->header('X-Request-ID') ?? Str::uuid()->toString();

        // Store in request for use in logging/Sentry
        $request->attributes->set('request_id', $requestId);

        // Make available to logging context (if Sentry is configured)
        if (function_exists('\Sentry\configureScope')) {
            \Sentry\configureScope(function ($scope) use ($requestId) {
                $scope->setTag('request_id', $requestId);
            });
        }

        $response = $next($request);

        // Return request ID in response header
        return $response->header('X-Request-ID', $requestId);
    }
}
