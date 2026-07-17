<?php declare(strict_types=1);
namespace App\Observers;

use App\Models\OperationalAction;
use App\Models\TapAlert;
use App\Services\CacheService;

/**
 * Uma ação operacional é o evento mais recente do seu componente, por isso
 * altera o estado atual da piscina no esquema. Este observer trata dos efeitos
 * colaterais desse novo estado: gerir o alerta de torneira (igual ao registo
 * diário) e invalidar as caches do painel/esquema para o estado aparecer já.
 */
class OperationalActionObserver
{
    public function __construct(private CacheService $cacheService)
    {
    }

    public function created(OperationalAction $acao): void
    {
        if ($acao->tipo === OperationalAction::TIPO_TORNEIRA) {
            $this->gerirTorneira($acao);
        }

        $this->cacheService->invalidatePoolData();
        $this->cacheService->invalidateAllAlerts();

        if (auth()->check()) {
            \Illuminate\Support\Facades\Cache::forget('alertas_' . auth()->id());
        }
    }

    private function gerirTorneira(OperationalAction $acao): void
    {
        $modo = $acao->dados['agua_modo'] ?? null;
        if ($modo === null || $modo === '') {
            return;
        }

        $aberto = TapAlert::query()
            ->where('pool_id', $acao->pool_id)
            ->whereNull('resolved_at')
            ->latest('opened_at')
            ->first();

        if ($modo === 'on_com_agua') {
            if (! $aberto) {
                TapAlert::create([
                    'pool_id' => $acao->pool_id,
                    'opened_by' => $acao->user_id,
                    'opened_at' => $acao->registado_em,
                ]);
            }

            return;
        }

        if ($aberto) {
            $aberto->update([
                'resolved_at' => $acao->registado_em,
                'resolved_by' => $acao->user_id,
                'resolution' => 'acao_operacional',
            ]);
        }
    }
}
