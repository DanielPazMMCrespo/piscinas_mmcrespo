<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\MotivoEncerramento;
use App\Constants\UserRole;
use App\Models\AlertState;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Models\TapAlert;
use App\Models\User;
use App\Notifications\PiscinaEncerradaNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Encerrar e reabrir piscinas, e responder à pergunta "esta piscina estava
 * aberta neste dia?" — fonte única para toda a app.
 *
 * [AI_CONTEXT]
 * - Nunca recalcular o estado de encerramento noutro sítio: usar mapa() nos
 *   ecrãs de intervalo (heatmap, gráficos, PDF) e Pool::estaEncerradaEm() num
 *   único dia. Uma segunda implementação divergiria do livro sanitário.
 * - mapa() serve-se de UMA query para toda a janela. Não chamar
 *   estaEncerradaEm() dentro de um ciclo por dia.
 */
class PoolClosureService
{
    /**
     * Encerra uma piscina.
     *
     * @param  array{motivo: string, inicio?: mixed, fim?: mixed, agua_em_tratamento?: bool, observacoes?: ?string}  $dados
     *
     * @throws \DomainException Motivo inválido, intervalo inválido ou sobreposição com encerramento existente.
     */
    public function encerrar(Pool $piscina, array $dados, User $utilizador): PoolClosure
    {
        $motivo = (string) ($dados['motivo'] ?? '');
        if (! MotivoEncerramento::isValid($motivo)) {
            throw new \DomainException('Motivo de encerramento inválido.');
        }

        $inicio = Carbon::parse($dados['inicio'] ?? Carbon::now())->startOfDay();
        $fim = isset($dados['fim']) && $dados['fim'] !== null && $dados['fim'] !== ''
            ? Carbon::parse($dados['fim'])->startOfDay()
            : null;

        if ($fim !== null && $fim->lessThan($inicio)) {
            throw new \DomainException('A data de fim não pode ser anterior à data de início.');
        }

        if ($this->temSobreposicao($piscina, $inicio, $fim)) {
            throw new \DomainException(
                "{$piscina->nome_completo} já tem um encerramento registado que se sobrepõe a este período."
            );
        }

        return DB::transaction(function () use ($piscina, $motivo, $inicio, $fim, $dados, $utilizador): PoolClosure {
            $encerramento = $piscina->encerramentos()->create([
                'inicio' => $inicio,
                'fim' => $fim,
                'motivo' => $motivo,
                'agua_em_tratamento' => (bool) ($dados['agua_em_tratamento'] ?? false),
                'observacoes' => $dados['observacoes'] ?? null,
                'encerrada_por' => $utilizador->id,
            ]);

            // Uma torneira aberta ou um cartão pendente no Kanban de uma piscina
            // que acabou de encerrar deixam de ser accionáveis — senão ficam lá
            // para sempre, e é exactamente o ruído que este plano vem eliminar.
            if ($encerramento->esta_vigente) {
                $this->resolverPendentes($piscina, $utilizador);
            }

            $this->notificar($encerramento, $piscina);

            return $encerramento;
        });
    }

    /**
     * @param  Collection<int, Pool>  $piscinas
     * @param  array{motivo: string, inicio?: mixed, fim?: mixed, agua_em_tratamento?: bool, observacoes?: ?string}  $dados
     * @return array{encerradas: Collection<int, PoolClosure>, erros: array<string, string>}
     */
    public function encerrarEmLote(Collection $piscinas, array $dados, User $utilizador): array
    {
        $encerradas = collect();
        $erros = [];

        foreach ($piscinas as $piscina) {
            try {
                $encerradas->push($this->encerrar($piscina, $dados, $utilizador));
            } catch (\DomainException $e) {
                $erros[$piscina->nome_completo] = $e->getMessage();
            }
        }

        return ['encerradas' => $encerradas, 'erros' => $erros];
    }

    /**
     * Reabre uma piscina a partir de $dataReabertura (primeiro dia aberto).
     *
     * 'fim' é o último dia encerrado, logo grava-se dataReabertura - 1 dia. Se
     * isso cair antes do início, o encerramento nunca produziu efeito (desfazer
     * no mesmo dia) e é apagado em vez de ficar com um intervalo inválido.
     *
     * @throws \DomainException Se a piscina não tiver encerramento vigente.
     */
    public function reabrir(Pool $piscina, User $utilizador, ?CarbonInterface $dataReabertura = null): ?PoolClosure
    {
        $reabertura = ($dataReabertura ?? Carbon::now())->copy()->startOfDay();
        $encerramento = $piscina->encerramentoEm($reabertura);

        if ($encerramento === null) {
            throw new \DomainException("{$piscina->nome_completo} não está encerrada nesta data.");
        }

        return DB::transaction(function () use ($encerramento, $reabertura, $utilizador): ?PoolClosure {
            $novoFim = $reabertura->copy()->subDay();

            if ($novoFim->lessThan($encerramento->inicio->copy()->startOfDay())) {
                $encerramento->delete();

                return null;
            }

            $encerramento->update([
                'fim' => $novoFim,
                'reaberta_por' => $utilizador->id,
                'reaberta_em' => Carbon::now(),
            ]);

            $this->notificar($encerramento, $encerramento->piscina, reaberta: true);

            return $encerramento;
        });
    }

    /**
     * Mapa de encerramentos por piscina para uma janela de datas. UMA query,
     * usada por todos os ecrãs de intervalo.
     *
     * @param  array<int, int>|Collection<int, int>  $poolIds
     * @return array<int, array<int, array{inicio: Carbon, fim: ?Carbon, motivo: string, agua_em_tratamento: bool}>>
     */
    public function mapa(array|Collection $poolIds, CarbonInterface $inicio, CarbonInterface $fim): array
    {
        $ids = collect($poolIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return PoolClosure::query()
            ->whereIn('pool_id', $ids)
            ->queIntersetam($inicio, $fim)
            ->orderBy('inicio')
            ->get()
            ->groupBy('pool_id')
            ->map(fn (Collection $grupo) => $grupo->map(fn (PoolClosure $e) => [
                'inicio' => $e->inicio->copy()->startOfDay(),
                'fim' => $e->fim?->copy()->startOfDay(),
                'motivo' => $e->motivo,
                'agua_em_tratamento' => $e->agua_em_tratamento,
            ])->values()->all())
            ->all();
    }

    /**
     * Consulta um mapa devolvido por mapa(). Sem queries.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $mapa
     * @return array{inicio: Carbon, fim: ?Carbon, motivo: string, agua_em_tratamento: bool}|null
     */
    public static function encerramentoNoMapa(array $mapa, int $poolId, CarbonInterface $dia): ?array
    {
        $alvo = $dia->copy()->startOfDay();

        foreach ($mapa[$poolId] ?? [] as $periodo) {
            if ($periodo['inicio']->greaterThan($alvo)) {
                continue;
            }

            if ($periodo['fim'] === null || $periodo['fim']->greaterThanOrEqualTo($alvo)) {
                return $periodo;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $mapa
     */
    public static function encerradaNoMapa(array $mapa, int $poolId, CarbonInterface $dia): bool
    {
        return self::encerramentoNoMapa($mapa, $poolId, $dia) !== null;
    }

    /**
     * Encerramentos vigentes hoje, indexados por pool_id. Cacheado — é lido em
     * quase todos os pedidos (dashboard, alertas, formulários).
     *
     * @return array<int, PoolClosure>
     */
    public function vigentesHoje(): array
    {
        $cache = app(CacheService::class);
        $cacheados = $cache->getClosureMap();

        if ($cacheados !== null) {
            return $cacheados;
        }

        $vigentes = PoolClosure::query()
            ->vigenteEm(Carbon::now())
            ->get()
            ->keyBy('pool_id')
            ->all();

        $cache->cacheClosureMap($vigentes);

        return $vigentes;
    }

    private function temSobreposicao(Pool $piscina, CarbonInterface $inicio, ?CarbonInterface $fim): bool
    {
        return $piscina->encerramentos()
            ->where(function ($query) use ($inicio, $fim): void {
                // Existente começa antes (ou no fim) do novo período...
                $query->when(
                    $fim !== null,
                    fn ($q) => $q->whereDate('inicio', '<=', $fim),
                );

                // ...e termina depois (ou no início) do novo período.
                $query->where(function ($q) use ($inicio): void {
                    $q->whereNull('fim')->orWhereDate('fim', '>=', $inicio);
                });
            })
            ->exists();
    }

    /**
     * Admin, gestor e técnico, mais os nadadores-salvadores desta piscina — que
     * são quem deixa (ou volta) a ter registos diários para fazer.
     */
    private function notificar(PoolClosure $encerramento, ?Pool $piscina, bool $reaberta = false): void
    {
        if ($piscina === null) {
            return;
        }

        // whereHas em vez do scope role() do spatie: esse lança RoleDoesNotExist
        // se o cargo não existir na BD, e encerrar uma piscina não deve falhar
        // por causa de quem havia para notificar.
        $destinatarios = User::query()
            ->where(function ($query) use ($piscina): void {
                $query
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', [
                        UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO,
                    ]))
                    ->orWhereHas('piscinas', fn ($q) => $q->where('pools.id', $piscina->id));
            })
            ->get();

        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send(
            $destinatarios,
            new PiscinaEncerradaNotification($encerramento, $piscina->nome_completo, $reaberta),
        );
    }

    private function resolverPendentes(Pool $piscina, User $utilizador): void
    {
        TapAlert::query()
            ->where('pool_id', $piscina->id)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => Carbon::now(),
                'resolved_by' => $utilizador->id,
                'resolution' => 'Piscina encerrada',
            ]);

        // As chaves do Kanban são "tipo|poolId|data" ou "tipo|registoId" — só as
        // primeiras são identificáveis pela piscina sem recalcular os alertas.
        AlertState::query()
            ->where('status', 'pendente')
            ->where('alert_key', 'like', "%|{$piscina->id}|%")
            ->update([
                'status' => 'resolvido',
                'moved_by' => $utilizador->id,
                'moved_at' => Carbon::now(),
            ]);
    }
}
