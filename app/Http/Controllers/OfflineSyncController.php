<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\OperationalAction;
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

        abort_unless($user->can('create', DailyRecord::class), 403, 'Sem permissão para criar registos diários.');

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

    /**
     * Recebe um array de ações operacionais submetidas em modo offline e sincroniza-os com a base de dados.
     * Autorização replica OperationalActionResource::canCreate() — só Admin/Técnico, porque
     * este endpoint cria o registo diretamente e não passa pelas policies do Filament.
     */
    public function storeOperationalActions(Request $request): JsonResponse
    {
        $user = auth()->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Não autenticado.'], 401);
        }

        if (! $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            return response()->json(['success' => false, 'message' => 'Sem permissão para criar ações operacionais.'], 403);
        }

        $actionsPayload = $request->input('records', []);
        if (! is_array($actionsPayload) || empty($actionsPayload)) {
            return response()->json(['success' => true, 'synced_count' => 0, 'synced_ids' => []]);
        }

        $syncedIds = [];
        $syncedCount = 0;
        $failed = [];

        foreach ($actionsPayload as $item) {
            $offlineId = $item['offline_id'] ?? null;
            $data = $item['data'] ?? [];

            if (! is_array($data) || empty($data)) {
                if ($offlineId !== null) {
                    $syncedIds[] = $offlineId;
                }

                continue;
            }

            try {
                $validated = validator($data, [
                    'pool_id' => ['required', 'integer', 'exists:pools,id'],
                    'tipo' => ['required', 'string', 'in:'.implode(',', array_keys(OperationalAction::TIPOS))],
                    'registado_em' => ['required', 'date', 'before_or_equal:now', 'after:'.now()->subDays(7)->toDateString()],
                    'observacoes' => ['nullable', 'string', 'max:2000'],
                    'dados' => ['nullable', 'array'],
                    'foto' => ['nullable', 'string', 'max:255'],
                ])->validate();

                $validated['user_id'] = $user->id;
                OperationalAction::create($validated);

                $syncedCount += 1;

                if ($offlineId !== null) {
                    $syncedIds[] = $offlineId;
                }
            } catch (\Throwable $e) {
                Log::error('Erro na sincronização offline da ação operacional', [
                    'offline_id' => $offlineId,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                if ($offlineId !== null) {
                    $failed[] = ['offline_id' => $offlineId, 'motivo' => $e->getMessage()];
                }
            }
        }

        return response()->json([
            'success' => count($failed) === 0,
            'synced_count' => $syncedCount,
            'synced_ids' => $syncedIds,
        ], count($failed) === 0 ? 200 : 207);
    }
}
