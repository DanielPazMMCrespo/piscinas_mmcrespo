<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\UserRole;
use App\Jobs\ProcessDailyRecordAfterCreate;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class DailyRecordService
{
    /**
     * Campos de $poolData aceites do cliente por piscina. Deliberadamente exclui
     * `user_id`, `registado_em`, `hora_colheita`, `e_correcao`, `corrige_registo_id`
     * e `razao_correcao` — apesar de estarem no $fillable do model, são calculados
     * no servidor (em $commonData) ou pertencem só ao fluxo de correção append-only,
     * nunca ao registo normal criado por este serviço.
     */
    private const CAMPOS_PISCINA_PERMITIDOS = [
        'cloro_livre', 'cloro_total', 'ph', 'temperatura', 'transparencia',
        'caleira_feita', 'renovacao_agua', 'pressao_filtro', 'observacoes',
        'ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura', 'banhistas',
        'filtro_faz_retrolavagem', 'numero_lavagens_filtro',
        'filtro_foto_retrolavagem', 'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal',
        'bomba_ferrada', 'bomba_foto', 'contador_valor', 'contador_foto', 'torneira_foto', 'agua_modo',
        'tanque_ok', 'tanque_observacoes', 'tanque_foto', 'analises_fotos',
    ];

    /**
     * Cria os registos diários para uma ou várias piscinas em nome do utilizador autenticado ou especificado.
     */
    public function createRecords(?User $user, array $data): ?DailyRecord
    {
        $userId = $user?->id ?? (isset($data['user_id']) ? (int) $data['user_id'] : null);

        if ($userId === null) {
            throw new InvalidArgumentException('Utilizador não autenticado ou ID de utilizador ausente.');
        }

        // A hora da colheita é a hora oficial do registo: registado_em passa a ser
        // a data escolhida + a hora da colheita (o instante da submissão fica no
        // created_at automático do model, para auditoria).
        $horaColheita = $data['hora_colheita'] ?? null;
        $registadoEm = Carbon::parse($data['registado_em'] ?? now());
        $hora = filled($horaColheita) ? Carbon::parse($horaColheita) : now();
        $registadoEm->setTime($hora->hour, $hora->minute, 0);

        $commonData = [
            'user_id' => $userId,
            'registado_em' => $registadoEm,
            'hora_colheita' => filled($horaColheita) ? $registadoEm->format('H:i:s') : null,
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

        $this->recusarPiscinasParadas(array_keys($poolsData), $registadoEm);

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

                $poolData = array_intersect_key($poolData, array_flip(self::CAMPOS_PISCINA_PERMITIDOS));

                // $commonData depois de $poolData: mesmo que a whitelist acima
                // deixasse passar algo indevido, os campos calculados no servidor
                // (user_id, registado_em, ...) continuam a ganhar ao payload do cliente.
                $recordData = array_merge($poolData, $commonData, ['pool_id' => (int) $poolId]);
                $lastRecord = DailyRecord::create($recordData);

                if (! empty($adicoes) && is_array($adicoes)) {
                    $lastRecord->adicoes()->createMany($adicoes);
                }

                ProcessDailyRecordAfterCreate::dispatch($lastRecord->id, $userId);
            }
        });

        return $lastRecord;
    }

    /**
     * Uma piscina encerrada e parada não aceita registos diários. A validação é
     * contra a DATA DO REGISTO e não contra hoje: o formulário permite datas
     * retroativas, e o que importa é se a piscina estava a funcionar nesse dia.
     *
     * O Select já não oferece estas piscinas — isto fecha o caminho de um
     * deep-link antigo, de um rascunho restaurado ou de um POST forjado.
     *
     * @param  array<int, int|string>  $poolIds
     *
     * @throws ValidationException
     */
    private function recusarPiscinasParadas(array $poolIds, Carbon $registadoEm): void
    {
        if ($poolIds === []) {
            return;
        }

        $paradas = Pool::query()
            ->whereIn('id', array_map('intval', $poolIds))
            ->whereHas('encerramentos', fn ($q) => $q
                ->vigenteEm($registadoEm)
                ->where('agua_em_tratamento', false))
            ->get();

        if ($paradas->isEmpty()) {
            return;
        }

        $dia = $registadoEm->format('d/m/Y');
        $nomes = $paradas->map(fn (Pool $p) => $p->nome_completo)->implode(', ');

        throw ValidationException::withMessages([
            'data.pools' => $paradas->count() === 1
                ? "{$nomes} estava encerrada em {$dia}, sem tratamento de água — não há registo diário a fazer."
                : "Estas piscinas estavam encerradas em {$dia}, sem tratamento de água: {$nomes}.",
        ]);
    }
}
