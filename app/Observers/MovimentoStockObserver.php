<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\DosingContainerLog;
use App\Models\StockInstallationLog;
use App\Models\StockWarehouseLog;
use App\Support\Auditoria;
use Illuminate\Database\Eloquent\Model;

/**
 * Espelha os movimentos de stock e de bidões no `activity_log`.
 *
 * [AI_CONTEXT]
 * - Os Resources de movimentos continuam a ser a vista operacional (filtros
 *   por produto/instalação/fornecedor). Este espelho existe para que a página
 *   Activity Log seja a linha do tempo COMPLETA — quem audita não deve ter de
 *   saber que o stock vive noutra tabela.
 * - Corre dentro da transação de `StockService`: se a transação abortar, a
 *   entrada de auditoria desaparece com ela. É o comportamento desejado.
 * - Só `created`. Estes registos são imutáveis por design.
 */
class MovimentoStockObserver
{
    public function created(Model $log): void
    {
        [$descricao, $propriedades] = match (true) {
            $log instanceof StockWarehouseLog => $this->armazem($log),
            $log instanceof StockInstallationLog => $this->instalacao($log),
            $log instanceof DosingContainerLog => $this->bidao($log),
            default => [null, []],
        };

        if ($descricao === null) {
            return;
        }

        Auditoria::registar(
            Auditoria::CANAL_STOCK,
            $descricao,
            $propriedades,
            alvo: $log,
            autor: $log->utilizador,
        );
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function armazem(StockWarehouseLog $log): array
    {
        $produto = $log->produto?->name ?? 'produto desconhecido';
        $unidade = $log->produto?->unidade ?? '';
        $movimento = $log->tipo_movimento === 'entrada' ? 'Entrada' : 'Saída';

        return [
            trim("Armazém — {$movimento} de {$log->quantity} {$unidade} de {$produto}."),
            array_filter([
                'produto' => $produto,
                'quantidade' => (float) $log->quantity,
                'movimento' => $log->tipo_movimento,
                'fornecedor' => $log->fornecedor,
            ], fn ($v) => $v !== null),
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function instalacao(StockInstallationLog $log): array
    {
        $stock = $log->stockInstalacao;
        $produto = $stock?->produto?->name ?? 'produto desconhecido';
        $unidade = $stock?->produto?->unidade ?? '';
        $instalacao = $stock?->instalacao?->name ?? 'instalação desconhecida';
        $movimento = $log->tipo_movimento === 'consumo' ? 'Consumo' : 'Entrada';

        return [
            trim("{$instalacao} — {$movimento} de {$log->quantity} {$unidade} de {$produto}."),
            [
                'instalacao' => $instalacao,
                'produto' => $produto,
                'quantidade' => (float) $log->quantity,
                'movimento' => $log->tipo_movimento,
            ],
        ];
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function bidao(DosingContainerLog $log): array
    {
        $container = $log->container;
        $tipo = $container?->tipoLabel() ?? 'bidão';
        $piscina = $container?->piscina?->name ?? 'piscina desconhecida';
        $movimento = $log->tipo_movimento === 'reabastecimento' ? 'Reabastecimento' : 'Dosagem';

        return [
            "Bidão {$tipo} ({$piscina}) — {$movimento} de {$log->quantidade_ml} ml.",
            array_filter([
                'piscina' => $piscina,
                'bidao' => $tipo,
                'quantidade_ml' => (float) $log->quantidade_ml,
                'restante_apos_ml' => (float) $log->restante_apos_ml,
                'movimento' => $log->tipo_movimento,
                'origem' => $log->origem,
            ], fn ($v) => $v !== null),
        ];
    }
}
