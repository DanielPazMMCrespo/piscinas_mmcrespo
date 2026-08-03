<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity;

/**
 * Ponto único de escrita no trilho de auditoria (`activity_log`).
 *
 * [AI_CONTEXT]
 * - Todo o evento de negócio relevante para um auditor passa por aqui, para
 *   que a página Activity Log seja a linha do tempo completa e não só um
 *   espelho de CRUD. Um `Log::info()` em `storage/logs` não serve: o disco do
 *   Railway é efémero e o admin não lhe tem acesso pelo painel.
 * - `contexto()` anexa IP e user-agent. Sem isso não é auditoria, é histórico.
 * - Nunca registar aqui credenciais, tokens ou passwords.
 */
final class Auditoria
{
    public const CANAL_AUTH = 'auth';

    public const CANAL_DEFINICOES = 'definicoes';

    public const CANAL_STOCK = 'stock';

    public const CANAL_SISTEMA = 'sistema';

    public const CANAL_OPERACAO = 'operacao';

    /**
     * @param  array<string, mixed>  $propriedades
     */
    public static function registar(
        string $canal,
        string $descricao,
        array $propriedades = [],
        ?Model $alvo = null,
        ?Model $autor = null,
    ): ?Activity {
        $logger = activity($canal)->withProperties($propriedades + self::contexto());

        if ($alvo !== null) {
            $logger->performedOn($alvo);
        }

        if ($autor !== null) {
            $logger->causedBy($autor);
        }

        return $logger->log($descricao);
    }

    /**
     * Evento sem autor humano (scheduler, jobs, integrações).
     *
     * @param  array<string, mixed>  $propriedades
     */
    public static function sistema(string $descricao, array $propriedades = []): ?Activity
    {
        return activity(self::CANAL_SISTEMA)
            ->withProperties($propriedades)
            ->log($descricao);
    }

    /**
     * IP e user-agent do pedido atual. Vazio em consola (scheduler/artisan).
     *
     * @return array<string, string>
     */
    public static function contexto(): array
    {
        // Scheduler e jobs de consola não têm pedido: um IP ali seria inventado.
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return [];
        }

        $request = request();

        return array_filter([
            'ip' => (string) $request->ip(),
            'ua' => Str::limit((string) $request->userAgent(), 180, ''),
        ]);
    }
}
