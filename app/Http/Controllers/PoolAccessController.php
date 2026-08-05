<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PoolAccessRequest;
use App\Services\PoolAccessRequestService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PoolAccessController extends Controller
{
    public function __construct(private readonly PoolAccessRequestService $service) {}

    public function show(): View|RedirectResponse
    {
        $user = auth()->user();

        if (! $this->service->estaBloqueado($user)) {
            return redirect('/admin');
        }

        return view('pool-access.blocked', [
            'piscinas' => $this->service->piscinasBloqueantes($user),
            'pedidoPendente' => $this->service->pedidoPendente($user),
            'ultimoPedido' => PoolAccessRequest::query()->where('user_id', $user->id)->latest()->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = auth()->user();

        if (! $this->service->estaBloqueado($user)) {
            return redirect('/admin');
        }

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'motivo.required' => 'Descreva o motivo do pedido.',
            'motivo.min' => 'Descreva com mais detalhe (mínimo 10 caracteres).',
        ]);

        try {
            $this->service->solicitar($user, $validated['motivo']);
        } catch (DomainException $e) {
            return back()->withErrors(['motivo' => $e->getMessage()]);
        }

        return redirect('/piscinas-encerradas')->with('success', 'Pedido enviado ao administrador.');
    }
}
