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

        if (empty($token) || $request->bearerToken() !== $token) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        $piscinasAtivas = Pool::query()->where('active', true)->get();

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
            ];
        })->values();

        return response()->json([
            'timestamp' => now()->toIso8601String(),
            'piscinas' => [
                'total_ativas' => $piscinasAtivas->count(),
                'com_registo_hoje' => DailyRecord::query()
                    ->whereIn('pool_id', $piscinasAtivas->pluck('id'))
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
