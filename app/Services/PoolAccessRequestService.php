<?php

declare(strict_types=1);

namespace App\Services;

use App\Constants\PoolAccessRequestStatus;
use App\Constants\UserRole;
use App\Models\Pool;
use App\Models\PoolAccessRequest;
use App\Models\User;
use App\Notifications\PedidoAcessoContaNotification;
use App\Notifications\PedidoAcessoRespondidoNotification;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Bloqueia a conta do nadador-salvador quando todas as piscinas atribuídas
 * estão paradas, e gere o pedido de acesso temporário ao administrador.
 *
 * [AI_CONTEXT]
 * - Fonte única do bloqueio: o middleware BlockClosedPoolAccess e o
 *   PoolAccessController usam sempre estaBloqueado()/piscinasBloqueantes().
 *   Nunca recalcular a condição noutro sítio.
 * - O critério é Pool::estaParadaEm() (encerrada E sem tratamento de água),
 *   nunca estaEncerradaEm(): com a água em tratamento o registo diário
 *   continua obrigatório e o NS tem de conseguir entrar.
 * - Uma aprovação só cobre o(s) encerramento(s) vigente(s) no momento da
 *   decisão. Se a piscina reabrir e voltar a encerrar depois, é um
 *   PoolClosure novo com 'inicio' mais recente do que a aprovação — o
 *   nadador-salvador volta a ficar bloqueado e tem de pedir outra vez.
 */
class PoolAccessRequestService
{
    /** Piscinas atribuídas ao NS que estão paradas — vazio se tiver pelo menos uma com trabalho a fazer. */
    public function piscinasBloqueantes(User $user): Collection
    {
        if (! $user->hasRole(UserRole::NADADOR_SALVADOR)) {
            return collect();
        }

        $piscinas = $user->piscinas()->with('encerramentos')->get();

        // Pelo regime, não pelo encerramento: uma piscina fechada ao público
        // com a água em tratamento mantém a química e o registo diário
        // obrigatório, logo o NS tem de continuar a entrar. Bloquear por
        // estaEncerradaEm() punha-o fora do painel numa paragem técnica, ao
        // contrário do que o formulário e o DailyRecordService já assumiam.
        if ($piscinas->isEmpty() || $piscinas->contains(fn (Pool $p) => ! $p->estaParadaEm())) {
            return collect();
        }

        return $piscinas;
    }

    public function estaBloqueado(User $user): bool
    {
        return $this->piscinasBloqueantes($user)->isNotEmpty() && ! $this->temAcessoAprovado($user);
    }

    public function pedidoPendente(User $user): ?PoolAccessRequest
    {
        return PoolAccessRequest::query()
            ->where('user_id', $user->id)
            ->pendentes()
            ->latest()
            ->first();
    }

    /** @throws DomainException Já existe um pedido pendente. */
    public function solicitar(User $user, string $motivo): PoolAccessRequest
    {
        if ($this->pedidoPendente($user) !== null) {
            throw new DomainException('Já tem um pedido de acesso pendente.');
        }

        $pedido = PoolAccessRequest::create([
            'user_id' => $user->id,
            'motivo' => $motivo,
            'status' => PoolAccessRequestStatus::PENDENTE,
        ]);

        $this->notificarAdministradores($pedido);

        return $pedido;
    }

    public function aprovar(PoolAccessRequest $pedido, User $admin, ?string $resposta = null): void
    {
        $pedido->update([
            'status' => PoolAccessRequestStatus::APROVADO,
            'resposta_admin' => $resposta,
            'decidido_por' => $admin->id,
            'decidido_em' => Carbon::now(),
        ]);

        $pedido->user?->notify(new PedidoAcessoRespondidoNotification($pedido));
    }

    public function negar(PoolAccessRequest $pedido, User $admin, ?string $resposta = null): void
    {
        $pedido->update([
            'status' => PoolAccessRequestStatus::NEGADO,
            'resposta_admin' => $resposta,
            'decidido_por' => $admin->id,
            'decidido_em' => Carbon::now(),
        ]);

        $pedido->user?->notify(new PedidoAcessoRespondidoNotification($pedido));
    }

    private function temAcessoAprovado(User $user): bool
    {
        $pedido = PoolAccessRequest::query()
            ->where('user_id', $user->id)
            ->where('status', PoolAccessRequestStatus::APROVADO)
            ->latest('decidido_em')
            ->first();

        if ($pedido === null || $pedido->decidido_em === null) {
            return false;
        }

        $inicioMaisRecente = $user->piscinas()
            ->get()
            ->map(fn (Pool $p) => $p->encerramentoEm()?->inicio)
            ->filter()
            ->max();

        return $inicioMaisRecente === null || $pedido->decidido_em->greaterThanOrEqualTo($inicioMaisRecente);
    }

    private function notificarAdministradores(PoolAccessRequest $pedido): void
    {
        $destinatarios = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', UserRole::ADMIN))
            ->get();

        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send($destinatarios, new PedidoAcessoContaNotification($pedido));
    }
}
