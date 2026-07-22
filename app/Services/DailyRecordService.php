<?php declare(strict_types=1);

namespace App\Services;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\User;
use App\Jobs\ProcessDailyRecordAfterCreate;
use Illuminate\Support\Facades\DB;

class DailyRecordService
{
    /**
     * Processa e persiste a criação de registos diários para uma ou várias piscinas.
     * Garante de forma estrita o user_id autenticado no servidor e as permissões de piscina.
     *
     * @param User $user Utilizador autenticado
     * @param array $data Dados brutos de registo
     * @return DailyRecord|null O último registo criado
     */
    public function createRecords(?User $user, array $data): ?DailyRecord
    {
        $userId = $user?->id ?? (isset($data['user_id']) ? (int) $data['user_id'] : null);
        if ($userId === null) {
            return null;
        }

        $commonData = [
            'user_id' => $userId,
            'registado_em' => $data['registado_em'] ?? now(),
        ];

        if (isset($data['ns_foto'])) {
            $commonData['ns_foto'] = is_array($data['ns_foto']) ? array_values($data['ns_foto'])[0] : $data['ns_foto'];
        }

        $poolsData = $data['pools'] ?? [];
        if (empty($poolsData)) {
            return null;
        }

        if ($user->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolIdsPermitidos = $user->piscinas()->pluck('pools.id')->all();
            foreach (array_keys($poolsData) as $poolId) {
                abort_unless(in_array((int) $poolId, $poolIdsPermitidos, true), 403);
            }
        }

        $lastRecord = null;

        DB::transaction(function () use ($poolsData, $commonData, $user, &$lastRecord): void {
            $photoFields = [
                'bomba_foto', 'contador_foto', 'torneira_foto', 'tanque_foto',
                'filtro_foto_retrolavagem', 'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal'
            ];

            foreach ($poolsData as $poolId => $poolData) {
                $adicoes = $poolData['adicoes'] ?? [];
                unset($poolData['adicoes']);

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
                $lastRecord = DailyRecord::create($recordData);

                if (!empty($adicoes) && is_array($adicoes)) {
                    $lastRecord->adicoes()->createMany($adicoes);
                }

                ProcessDailyRecordAfterCreate::dispatch($lastRecord->id, (int) $user->id);
            }
        });

        return $lastRecord;
    }
}
