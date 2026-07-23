<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\DailyRecordService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OfflineSyncController extends Controller
{
    /**
     * Recebe um array de registos diários submetidos em modo offline e sincroniza-os com a base de dados.
     * Utiliza o DailyRecordService para garantir validação de permissões e prevenção de IDOR.
     */
    public function storeDailyRecords(Request $request, DailyRecordService $dailyRecordService): JsonResponse
    {
        $user = auth()->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }

        $recordsPayload = $request->input('records', []);
        if (! is_array($recordsPayload) || empty($recordsPayload)) {
            return response()->json(['success' => true, 'synced_count' => 0, 'synced_ids' => []]);
        }

        $syncedIds = [];
        $syncedCount = 0;

        foreach ($recordsPayload as $item) {
            $offlineId = $item['offline_id'] ?? null;
            $data = $item['data'] ?? [];

            if (! is_array($data) || empty($data)) {
                if ($offlineId !== null) {
                    $syncedIds[] = $offlineId;
                }

                continue;
            }

            try {
                $poolsData = $data['pools'] ?? [];
                $poolCount = count($poolsData);

                $dailyRecordService->createRecords($user, $data);

                $syncedCount += $poolCount;

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
