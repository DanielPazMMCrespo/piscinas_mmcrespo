<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\UserRole;
use App\Jobs\ProcessDailyRecordAfterCreate;
use App\Models\DailyRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DailyRecordService
{
    /**
     * Cria os registos diários para uma ou várias piscinas em nome do utilizador autenticado ou especificado.
     */
    public function createRecords(?User $user, array $data): ?DailyRecord
    {
        $userId = $user?->id ?? (isset($data['user_id']) ? (int) $data['user_id'] : null);

        if ($userId === null) {
            throw new InvalidArgumentException('Utilizador não autenticado ou ID de utilizador ausente.');
        }

        $commonData = [
            'user_id' => $userId,
            'registado_em' => $data['registado_em'] ?? now(),
        ];

        if (isset($data['ns_foto'])) {
            $commonData['ns_foto'] = is_array($data['ns_foto']) ? array_values($data['ns_foto'])[0] : $data['ns_foto'];
        }

        $poolsData = $data['pools'] ?? [];
        $lastRecord = null;

        if ($user !== null && $user->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolIdsPermitidos = $user->piscinas()->pluck('pools.id')->all();
            foreach (array_keys($poolsData) as $poolId) {
                abort_unless(in_array((int) $poolId, $poolIdsPermitidos, true), 403, 'Acesso não autorizado a uma ou mais piscinas.');
            }
        }

        DB::transaction(function () use ($poolsData, $commonData, $userId, &$lastRecord): void {
            foreach ($poolsData as $poolId => $poolData) {
                $adicoes = $poolData['adicoes'] ?? [];
                unset($poolData['adicoes']);

                $photoFields = ['bomba_foto', 'contador_foto', 'torneira_foto', 'tanque_foto', 'filtro_foto_retrolavagem', 'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal'];
                foreach ($photoFields as $pf) {
                    if (isset($poolData[$pf])) {
                        if (is_array($poolData[$pf])) {
                            $poolData[$pf] = ! empty($poolData[$pf]) ? array_values($poolData[$pf])[0] : null;
                        } elseif ($poolData[$pf] === '') {
                            $poolData[$pf] = null;
                        }
                    } else {
                        $poolData[$pf] = null;
                    }
                }

                $recordData = array_merge($commonData, $poolData, ['pool_id' => (int) $poolId]);
                $lastRecord = DailyRecord::create($recordData);

                if (! empty($adicoes) && is_array($adicoes)) {
                    $lastRecord->adicoes()->createMany($adicoes);
                }

                ProcessDailyRecordAfterCreate::dispatch($lastRecord->id, $userId);
            }
        });

        return $lastRecord;
    }
}
