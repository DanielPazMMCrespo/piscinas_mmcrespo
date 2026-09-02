<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\Pool;
use App\Models\StockInstallation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint de métricas para integração com BI externo (Grafana/Metabase).
 * Protegido por token estático (services.metrics.token) — uso interno,
 * não é uma API pública multi-utilizador, por isso não justifica Sanctum.
 */
class MetricsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $token = config('services.metrics.token');
        $bearer = (string) $request->bearerToken();

        // `Illuminate\Support\Str` não tem método `equals()` — isto dava 500 em
        // todos os pedidos (erro fatal, nunca 401/200). `hash_equals()` é a
        // primitiva certa para comparar segredos: tempo constante, evita side
        // channel de timing. Sem token configurado o endpoint fica fechado
        // (nunca aberto por omissão) — reporta internos de todas as piscinas,
        // incidentes e stock, não se justifica um default público.
        if ($token === null || $token === '' || $bearer === '' || ! hash_equals($token, $bearer)) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        $piscinasAtivas = Pool::query()->where('active', true)->with('encerramentos')->get();

        // Separadas para o alerta externo não disparar por "piscina sem registo
        // hoje" numa piscina legitimamente encerrada.
        [$piscinasEncerradas, $piscinasOperacionais] = $piscinasAtivas
            ->partition(fn (Pool $piscina) => $piscina->estaEncerradaEm());

        $resolvidos30 = Incident::query()
            ->where('status', 'resolvido')
            ->whereNotNull('resolvido_em')
            ->where('ocorreu_em', '>=', now()->subDays(30))
            ->get(['ocorreu_em', 'resolvido_em']);

        $mttrMinutos30 = $resolvidos30->isNotEmpty()
            ? round($resolvidos30->avg(fn (Incident $i) => $i->ocorreu_em->diffInMinutes($i->resolvido_em)), 1)
            : null;

        $porPiscina = $piscinasAtivas->map(function (Pool $piscina) {
            $ultimoRegisto = DailyRecord::query()
                ->where('pool_id', $piscina->id)
                ->whereDoesntHave('correcoes')
                ->latest('registado_em')
                ->first();

            return [
                'id' => $piscina->id,
                'nome' => $piscina->nomeCompleto(' — '),
                'ultimo_registo_em' => $ultimoRegisto?->registado_em?->toIso8601String(),
                'conforme' => $ultimoRegisto ? empty($ultimoRegisto->listarViolacoes()) : null,
                'encerrada' => $piscina->estaEncerradaEm(),
            ];
        })->values();

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'piscinas' => [
                'total_ativas' => $piscinasOperacionais->count(),
                'total_encerradas' => $piscinasEncerradas->count(),
                'com_registo_hoje' => DailyRecord::query()
                    ->whereIn('pool_id', $piscinasOperacionais->pluck('id'))
                    ->whereDate('registado_em', now()->toDateString())
                    ->distinct('pool_id')
                    ->count('pool_id'),
                'detalhe' => $porPiscina,
            ],
            'incidentes' => [
                'abertos' => Incident::query()->where('status', 'aberto')->count(),
                'tempo_medio_resposta_minutos_30d' => $mttrMinutos30,
            ],
            'stock' => [
                'produtos_abaixo_do_minimo' => StockInstallation::query()
                    ->whereColumn('quantity', '<=', 'limite_minimo')
                    ->count(),
            ],
        ]);
    }
}
