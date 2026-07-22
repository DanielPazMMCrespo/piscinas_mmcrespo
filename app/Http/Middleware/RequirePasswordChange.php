<?php declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check() && auth()->user()->must_change_password) {
            if (! $request->is('primeiro-acesso', 'admin/logout', 'logout')) {
                return response()->redirectTo('/primeiro-acesso');
            }
        }

        return $next($request);
    }
}
