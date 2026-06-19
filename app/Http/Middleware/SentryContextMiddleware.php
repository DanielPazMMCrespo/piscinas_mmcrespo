<?php declare(strict_types=1);
namespace App\Http\Middleware;


use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

class SentryContextMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract pool_id from route parameter if available
        $poolId = $request->route('pool');

        // Get operation type from route name
        $routeName = $request->route()?->getName() ?? 'unknown';
        $operation = $this->getOperationType($routeName);

        // Set Sentry tags for this request (if Sentry is configured)
        if (function_exists('\Sentry\configureScope')) {
            \Sentry\configureScope(function (Scope $scope) use ($poolId, $operation, $request): void {
                if ($poolId) {
                    $scope->setTag('pool_id', (string) $poolId);
                }

                $scope->setTag('operation_type', $operation);
                $scope->setTag('route_name', $request->route()?->getName() ?? 'unknown');
                $scope->setTag('http_method', $request->getMethod());

                // Add request context
                $scope->setContext('request', [
                    'ip' => $request->ip(),
                    'method' => $request->getMethod(),
                    'path' => $request->getPathInfo(),
                    'query' => $this->sanitizeQuery($request->query()),
                ]);
            });
        }

        return $next($request);
    }

    /**
     * Determine operation type from route name.
     */
    private function getOperationType(string $routeName): string
    {
        $mapping = [
            'filament.admin.resources.daily-records.create' => 'daily_record_create',
            'filament.admin.resources.daily-records.edit' => 'daily_record_edit',
            'filament.admin.resources.filter-checks.create' => 'filter_check_create',
            'filament.admin.resources.incidents.create' => 'incident_create',
            'filament.admin.resources.stock-warehouse.transfer' => 'stock_transfer',
            'filament.admin.resources.stock-installation.create' => 'stock_installation_create',
        ];

        foreach ($mapping as $route => $operation) {
            if (str_contains($routeName, $route)) {
                return $operation;
            }
        }

        // Generic mapping based on pattern
        if (str_contains($routeName, 'create')) {
            return 'resource_create';
        }
        if (str_contains($routeName, 'edit')) {
            return 'resource_edit';
        }
        if (str_contains($routeName, 'delete')) {
            return 'resource_delete';
        }

        return 'unknown';
    }

    /**
     * Sanitize query parameters to avoid logging sensitive data.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function sanitizeQuery(array $query): array
    {
        $sensitive = ['password', 'token', 'secret', 'api_key', 'gemini_key', 'hanna_password'];

        foreach ($sensitive as $key) {
            if (isset($query[$key])) {
                $query[$key] = '***REDACTED***';
            }
        }

        return $query;
    }
}
