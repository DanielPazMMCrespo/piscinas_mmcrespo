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
     * Limites por campo, replicados dos bounds já vigentes no wizard do
     * Filament (`DailyRecordFormBuilder`) e na ação "Corrigir"
     * (`DailyRecordTableBuilder`). Vivem aqui porque o sync offline
     * (`OfflineSyncController`) nunca passa pelo formulário — sem isto um
     * `ns_ph = 99` ou uma `temperatura = 9999` entravam no livro sanitário
     * sem nenhuma guarda. `max: null` = sem teto (ex.: o contador só sobe).
     *
     * @var array<string, array{min: float, max: float|null}>
     */
    private const LIMITES_CAMPOS = [
        'ph' => ['min' => 0.0, 'max' => 14.0],
        'ns_ph' => ['min' => 0.0, 'max' => 14.0],
        'cloro_livre' => ['min' => 0.0, 'max' => 20.0],
        'ns_cloro_livre' => ['min' => 0.0, 'max' => 20.0],
        'cloro_total' => ['min' => 0.0, 'max' => 20.0],
        'ns_cloro_total' => ['min' => 0.0, 'max' => 20.0],
        'temperatura' => ['min' => 0.0, 'max' => 60.0],
        'ns_temperatura' => ['min' => 0.0, 'max' => 60.0],
        'transparencia' => ['min' => 0.0, 'max' => 99.99],
        'pressao_filtro' => ['min' => 0.0, 'max' => 10.0],
        'contador_valor' => ['min' => 0.0, 'max' => null],
        'banhistas' => ['min' => 0.0, 'max' => null],
        'numero_lavagens_filtro' => ['min' => 1.0, 'max' => null],
    ];

    /**
     * Um 0/0.00 nestes campos é quase sempre sonda avariada, falta de
     * reagente ou "não medido" — não uma leitura real. Mesma lista de
     * `DailyRecordFormBuilder::algumValorZero()`; só os campos do NS, porque
     * é o passo que existe em todos os registos (o técnico é opcional).
     */
    private const CAMPOS_ZERO_SUSPEITO = ['ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura'];

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

        // Validar que a data está dentro da janela permitida (offline sync com até 7 dias de atraso)
        if ($registadoEm->gt(now()->addMinutes(5)) || $registadoEm->lt(now()->subDays(7))) {
            throw ValidationException::withMessages([
                'registado_em' => 'A data do registo tem de estar entre os últimos 7 dias e agora (+5 min de tolerância).',
            ]);
        }

        $hora = filled($horaColheita) ? Carbon::parse($horaColheita) : now();
        $registadoEm->setTime($hora->hour, $hora->minute, 0);

        $commonData = [
            'user_id' => $userId,
            'registado_em' => $registadoEm,
            'hora_colheita' => filled($horaColheita) ? $registadoEm->format('H:i:s') : null,
        ];

        if (isset($data['ns_foto'])) {
            if (is_array($data['ns_foto'])) {
                $commonData['ns_foto'] = ! empty($data['ns_foto']) ? array_values($data['ns_foto'])[0] : null;
            } elseif ($data['ns_foto'] === '') {
                $commonData['ns_foto'] = null;
            } else {
                $commonData['ns_foto'] = $data['ns_foto'];
            }
        }

        $poolsData = $data['pools'] ?? [];
        $lastRecord = null;

        if (empty($poolsData)) {
            throw ValidationException::withMessages([
                'pools' => 'Nenhuma piscina selecionada para registo. Verifique se tem piscinas atribuídas.',
            ]);
        }

        if ($user !== null && $user->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolIdsPermitidos = array_map('intval', $user->piscinas()->pluck('pools.id')->all());
            foreach (array_keys($poolsData) as $poolId) {
                if (! in_array((int) $poolId, $poolIdsPermitidos, true)) {
                    throw ValidationException::withMessages([
                        'pools' => 'Acesso não autorizado a uma ou mais piscinas selecionadas.',
                    ]);
                }
            }
        }

        $this->validarRegrasNegocio($poolsData);
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
     * Regras de negócio por piscina — a mesma validação já feita pelo wizard
     * Filament (limites por campo, "cloro total >= cloro livre", "contador só
     * avança", "zero suspeito exige observações"), mas centralizada aqui para
     * cobrir também o sync offline, que nunca passa pelo formulário. Corre
     * ANTES da whitelist de campos ($poolData "cru"): um valor fora do
     * intervalo num campo conhecido é rejeitado com erro, não descartado em
     * silêncio pelo array_intersect_key.
     *
     * Requiredness fica no formulário — aqui só se valida o que está
     * preenchido (intervalos e consistência entre campos), nunca a
     * obrigatoriedade de um campo.
     *
     * @param  array<int|string, mixed>  $poolsData
     *
     * @throws ValidationException
     */
    private function validarRegrasNegocio(array $poolsData): void
    {
        $erros = [];

        foreach ($poolsData as $poolId => $poolData) {
            if (! is_array($poolData)) {
                continue;
            }

            foreach (self::LIMITES_CAMPOS as $campo => $limites) {
                if (! array_key_exists($campo, $poolData) || ! filled($poolData[$campo]) || ! is_numeric($poolData[$campo])) {
                    continue;
                }

                $valor = (float) $poolData[$campo];
                if ($valor < $limites['min'] || ($limites['max'] !== null && $valor > $limites['max'])) {
                    $erros["pools.{$poolId}.{$campo}"][] = "O valor de \"{$campo}\" está fora do intervalo permitido.";
                }
            }

            // Um combinado negativo é impossível — mesma regra de
            // CreateDailyRecord::validatePoolsCloro() e da ação "Corrigir".
            foreach ([['cloro_total', 'cloro_livre'], ['ns_cloro_total', 'ns_cloro_livre']] as [$totalKey, $livreKey]) {
                $total = $poolData[$totalKey] ?? null;
                $livre = $poolData[$livreKey] ?? null;

                if (filled($total) && filled($livre) && is_numeric($total) && is_numeric($livre) && (float) $total < (float) $livre) {
                    $erros["pools.{$poolId}.{$totalKey}"][] = 'O cloro total não pode ser inferior ao cloro livre.';
                }
            }

            $temZeroSuspeito = false;
            foreach (self::CAMPOS_ZERO_SUSPEITO as $campo) {
                if (array_key_exists($campo, $poolData) && filled($poolData[$campo]) && is_numeric($poolData[$campo]) && (float) $poolData[$campo] === 0.0) {
                    $temZeroSuspeito = true;
                    break;
                }
            }

            // Valor zero é aceite diretamente sem obrigar a preenchimento de observações

            // O contador só avança — mesma regra de
            // DailyRecordFormBuilder::ultimoRegisto().
            if (array_key_exists('contador_valor', $poolData) && filled($poolData['contador_valor']) && is_numeric($poolData['contador_valor'])) {
                $ultimo = DailyRecord::query()
                    ->where('pool_id', (int) $poolId)
                    ->whereDoesntHave('correcoes')
                    ->orderByDesc('registado_em')
                    ->orderByDesc('id')
                    ->first();

                if ($ultimo && $ultimo->contador_valor !== null && (float) $poolData['contador_valor'] < (float) $ultimo->contador_valor) {
                    $erros["pools.{$poolId}.contador_valor"][] = 'A leitura do contador ('.$poolData['contador_valor'].') é inferior à última registada ('.$ultimo->contador_valor.'). O contador só avança.';
                }
            }
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
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
