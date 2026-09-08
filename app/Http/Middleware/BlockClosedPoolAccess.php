<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockClosedPoolAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        // Bloqueio forçado desativado: utilizadores podem aceder normalmente ao painel;
        // piscinas encerradas são identificadas visualmente com a etiqueta [Encerrada] nos cartões.
        return $next($request);
    }
}
