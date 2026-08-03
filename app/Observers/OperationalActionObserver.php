<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DosingContainer;
use App\Models\HannaDevice;
use App\Models\OperationalAction;
use App\Models\SensorOutage;
use App\Models\TapAlert;
use App\Services\CacheService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Uma ação operacional é o evento mais recente do seu componente, por isso
 * altera o estado atual da piscina no esquema. Este observer trata dos efeitos
 * colaterais desse novo estado: gerir o alerta de torneira (igual ao registo
 * diário) e invalidar as caches do painel/esquema para o estado aparecer já.
 */
class OperationalActionObserver
{
    public function __construct(private CacheService $cacheService) {}

    public function created(OperationalAction $acao): void
    {
        $this->aplicarEfeitos($acao);
        $this->invalidarCaches();
    }

    /**
     * Editar uma ação re-sincroniza os efeitos colaterais, mas só quando faz
     * sentido:
     * - `dados` tem de ter mudado — editar só observacoes/foto não pode reabrir
     *   um TapAlert resolvido nem re-executar o reabastecimento do bidão;
     * - a ação tem de ser a mais recente do seu tipo para a piscina — editar uma
     *   ação já substituída por outra posterior não deve reescrever o estado
     *   atual (que reflete a ação mais recente, não esta).
     * `reabastecer()` faz SET do nível (não soma) e `gerirTorneira()` reconcilia
     * contra o alerta aberto — seguros de re-correr sob estas condições.
     */
    public function updated(OperationalAction $acao): void
    {
        if ($acao->wasChanged('dados') && $this->ehAcaoMaisRecente($acao)) {
            $this->aplicarEfeitos($acao);
        }

        $this->invalidarCaches();
    }

    private function aplicarEfeitos(OperationalAction $acao): void
    {
        if ($acao->tipo === OperationalAction::TIPO_TORNEIRA) {
            $this->comEfeitoResiliente('alerta de torneira', fn () => $this->gerirTorneira($acao));
        }

        if ($acao->tipo === OperationalAction::TIPO_REABASTECIMENTO_BIDAO) {
            $this->comEfeitoResiliente('reabastecimento do bidão', fn () => $this->reabastecerBidao($acao));
        }

        if ($acao->tipo === OperationalAction::TIPO_AVARIA_SONDA) {
            $this->comEfeitoResiliente('estado da sonda', fn () => $this->gerirAvariaSonda($acao));
        }
    }

    private function invalidarCaches(): void
    {
        $this->cacheService->invalidatePoolData();
        $this->cacheService->invalidateAllAlerts();

        if (auth()->check()) {
            Cache::forget('alertas_'.auth()->id());
        }
    }

    /**
     * Verdadeiro se não existe outra ação do mesmo tipo/piscina mais recente
     * (por `registado_em`, com o `id` a desempatar).
     */
    private function ehAcaoMaisRecente(OperationalAction $acao): bool
    {
        return ! OperationalAction::query()
            ->where('pool_id', $acao->pool_id)
            ->where('tipo', $acao->tipo)
            ->where('id', '!=', $acao->id)
            ->where(function ($q) use ($acao) {
                $q->where('registado_em', '>', $acao->registado_em)
                    ->orWhere(function ($q2) use ($acao) {
                        $q2->where('registado_em', $acao->registado_em)
                            ->where('id', '>', $acao->id);
                    });
            })
            ->exists();
    }

    /**
     * O registo já foi inserido quando este observer corre. Uma falha no efeito
     * colateral não pode rebentar a submissão (rollback do registo) — a criação
     * nunca deve falhar. Apanha o erro, regista-o e avisa o utilizador para
     * verificar o estado manualmente.
     */
    private function comEfeitoResiliente(string $descricao, \Closure $efeito): void
    {
        try {
            $efeito();
        } catch (\Throwable $e) {
            Log::error("Falha no efeito colateral ({$descricao}) de ação operacional", [
                'exception' => $e->getMessage(),
            ]);

            Notification::make()
                ->warning()
                ->title('Ação registada, mas o '.$descricao.' falhou')
                ->body('O registo foi guardado. Verifique manualmente o estado — o efeito automático não foi aplicado.')
                ->send();
        }
    }

    private function reabastecerBidao(OperationalAction $acao): void
    {
        $tipoBidao = $acao->dados['bidao_tipo'] ?? null;
        $quantidadeL = isset($acao->dados['quantidade_l']) && filled($acao->dados['quantidade_l']) ? (float) $acao->dados['quantidade_l'] : null;

        if ($tipoBidao === 'ambos') {
            $tipos = [DosingContainer::TIPO_CLORO, DosingContainer::TIPO_PH_MENOS];
        } else {
            $tipos = [$tipoBidao];
        }

        foreach ($tipos as $tipo) {
            if (! $tipo) {
                continue;
            }
            $container = DosingContainer::firstOrCreate(
                ['pool_id' => $acao->pool_id, 'tipo' => $tipo],
                ['capacidade_ml' => 20000, 'restante_ml' => 0.00]
            );
            if ($container) {
                $ml = ($quantidadeL !== null && $quantidadeL > 0)
                    ? $quantidadeL * 1000
                    : ($container->capacidade_ml ?? 20000);

                $container->reabastecer($ml, $acao->user_id, $acao->observacoes, $acao->registado_em);
            }
        }
    }

    /**
     * Abre, atualiza ou fecha a indisponibilidade da sonda da piscina. Só existe
     * uma avaria em aberto por piscina: reportar de novo com outro motivo (ex.:
     * "peça partida" passou a "em reparação") atualiza a mesma linha, para o
     * histórico não ficar com avarias sobrepostas da mesma sonda.
     */
    private function gerirAvariaSonda(OperationalAction $acao): void
    {
        $estado = $acao->dados['sonda_estado'] ?? null;

        if ($estado === null || $estado === '') {
            return;
        }

        $aberta = SensorOutage::abertaPara((int) $acao->pool_id);

        if ($estado === SensorOutage::ESTADO_RESOLVIDO) {
            $aberta?->update([
                'resolvida_em' => $acao->registado_em,
                'resolvida_por' => $acao->user_id,
                'resolved_action_id' => $acao->id,
            ]);

            return;
        }

        $dados = [
            'motivo' => $estado,
            'detalhe' => $acao->observacoes,
            'hanna_device_id' => HannaDevice::query()
                ->where('pool_id', $acao->pool_id)
                ->value('hanna_device_id'),
        ];

        if ($aberta !== null) {
            $aberta->update($dados);

            return;
        }

        SensorOutage::create($dados + [
            'pool_id' => $acao->pool_id,
            'aberta_em' => $acao->registado_em,
            'aberta_por' => $acao->user_id,
            'opened_action_id' => $acao->id,
        ]);
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
