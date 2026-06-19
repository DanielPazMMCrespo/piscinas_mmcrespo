<?php declare(strict_types=1);
namespace App\Http\Controllers;

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        try {
            $checks = [
                'database' => $this->checkDatabase(),
                'cache' => $this->checkCache(),
                'timestamp' => now()->toIso8601String(),
                'version' => $this->getVersion(),
            ];

            $status = collect($checks)
                ->except(['timestamp', 'version'])
                ->every(fn ($value) => $value === 'connected')
                ? 'ok'
                : 'degraded';

            return response()->json([
                'status' => $status,
                'database' => $checks['database'],
                'cache' => $checks['cache'],
                'timestamp' => $checks['timestamp'],
                'version' => $checks['version'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Health check failed', ['error' => $e->getMessage()]);

            return response()->json([
                'status' => 'error',
                'database' => 'error',
                'cache' => 'error',
                'timestamp' => now()->toIso8601String(),
                'version' => $this->getVersion(),
            ], 503);
        }
    }

    private function checkDatabase(): string
    {
        try {
            DB::connection()->select('select 1');

            return 'connected';
        } catch (\Throwable $e) {
            Log::error('Database health check failed', ['error' => $e->getMessage()]);

            return 'disconnected';
        }
    }

    private function checkCache(): string
    {
        try {
            $testKey = 'health_check_' . now()->timestamp;
            Cache::put($testKey, 'ok', 10);
            $value = Cache::get($testKey);
            Cache::forget($testKey);

            return $value === 'ok' ? 'connected' : 'disconnected';
        } catch (\Throwable $e) {
            Log::error('Cache health check failed', ['error' => $e->getMessage()]);

            return 'disconnected';
        }
    }

    private function getVersion(): string
    {
        try {
            $hash = trim(shell_exec('git rev-parse --short HEAD') ?? '');

            return $hash ?: 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
