<?php declare(strict_types=1);

namespace App\Http\Controllers;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Jobs\ProcessDailyRecordAfterCreate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OfflineSyncController extends Controller
{
    /**
     * Recebe um array de registos diários submetidos em modo offline e sincroniza-os com a base de dados.
     */
    public function storeDailyRecords(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }

        $recordsPayload = $request->input('records', []);
        if (!is_array($recordsPayload) || empty($recordsPayload)) {
            return response()->json(['success' => true, 'synced_count' => 0, 'synced_ids' => []]);
        }

        $syncedIds = [];
        $syncedCount = 0;

        $poolIdsPermitidos = null;
        if ($user->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolIdsPermitidos = $user->piscinas()->pluck('pools.id')->all();
        }

        foreach ($recordsPayload as $item) {
            $offlineId = $item['offline_id'] ?? null;
            $data = $item['data'] ?? [];

            if (!is_array($data) || empty($data)) {
                if ($offlineId !== null) {
                    $syncedIds[] = $offlineId;
                }
                continue;
            }

            $commonData = [
                'user_id' => $data['user_id'] ?? $user->id,
                'registado_em' => $data['registado_em'] ?? now(),
            ];

            if (isset($data['ns_foto'])) {
                $commonData['ns_foto'] = is_array($data['ns_foto']) ? array_values($data['ns_foto'])[0] : $data['ns_foto'];
            }

            $poolsData = $data['pools'] ?? [];

            try {
                DB::transaction(function () use ($poolsData, $commonData, $poolIdsPermitidos, $user, &$syncedCount): void {
                    foreach ($poolsData as $poolId => $poolData) {
                        if ($poolIdsPermitidos !== null && !in_array((int) $poolId, $poolIdsPermitidos, true)) {
                            continue;
                        }

                        $adicoes = $poolData['adicoes'] ?? [];
                        unset($poolData['adicoes']);

                        $photoFields = ['bomba_foto', 'contador_foto', 'torneira_foto', 'tanque_foto', 'filtro_foto_retrolavagem', 'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal'];
                        foreach ($photoFields as $pf) {
                            if (isset($poolData[$pf])) {
                                if (is_array($poolData[$pf])) {
                                    $poolData[$pf] = !empty($poolData[$pf]) ? array_values($poolData[$pf])[0] : null;
                                } elseif ($poolData[$pf] === '') {
                                    $poolData[$pf] = null;
                                }
                            } else {
                                $poolData[$pf] = null;
                            }
                        }

                        $recordData = array_merge($commonData, $poolData, ['pool_id' => (int) $poolId]);
                        $createdRecord = DailyRecord::create($recordData);

                        if (!empty($adicoes) && is_array($adicoes)) {
                            $createdRecord->adicoes()->createMany($adicoes);
                        }

                        ProcessDailyRecordAfterCreate::dispatch($createdRecord->id, (int) $user->id);
                        $syncedCount++;
                    }
                });

                if ($offlineId !== null) {
                    $syncedIds[] = $offlineId;
                }
            } catch (\Throwable $e) {
                Log::error('Erro na sincronização offline do registo', [
                    'offline_id' => $offlineId,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'synced_count' => $syncedCount,
            'synced_ids' => $syncedIds,
        ]);
    }
}
