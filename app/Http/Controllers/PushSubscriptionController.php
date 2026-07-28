<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Notifications\PedidoAtivacaoPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys.auth' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? null,
        );

        // Já ativou — o pedido do admin (se existia) deixa de fazer sentido
        // e não deve continuar a forçar o popup em cada carregamento.
        $request->user()->unreadNotifications()
            ->where('type', PedidoAtivacaoPushNotification::class)
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->json(['ok' => true]);
    }

    /**
     * Remove todas as subscrições do utilizador autenticado, mesmo sem o
     * endpoint local (útil quando o browser perdeu a permissão e o JS já não
     * consegue obter a subscrição para apagar uma a uma).
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->pushSubscriptions()->delete();

        return response()->json(['ok' => true]);
    }
}
