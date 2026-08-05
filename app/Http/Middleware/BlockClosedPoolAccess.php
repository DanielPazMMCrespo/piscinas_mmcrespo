<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PoolAccessRequestService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockClosedPoolAccess
{
    public function __construct(private readonly PoolAccessRequestService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $this->service->estaBloqueado($user)) {
            if (! $request->is('piscinas-encerradas', 'piscinas-encerradas/*', 'admin/logout', 'logout')) {
                return response()->redirectTo('/piscinas-encerradas');
            }
        }

        return $next($request);
    }
}
