<?php declare(strict_types=1);
namespace App\Http\Controllers;


use App\Services\MetricsService;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    /**
     * Expose Prometheus metrics.
     */
    public function metrics(): Response
    {
        if (!config('prometheus.enabled')) {
            return response('Metrics disabled', 403);
        }

        $metricsText = MetricsService::expose();

        return response($metricsText, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
