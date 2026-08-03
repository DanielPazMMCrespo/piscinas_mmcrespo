<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * [AI_CONTEXT]
 * Nunca colocar `$event->credentials` inteiro nas propriedades — traz a
 * password em claro para dentro da tabela de auditoria. Só o identificador.
 */
class LogUserAuthentication
{
    public function handleLogin(Login $event): void
    {
        Auditoria::registar(
            Auditoria::CANAL_AUTH,
            'Iniciou sessão no sistema.',
            autor: $this->autor($event->user),
        );
    }

    public function handleLogout(Logout $event): void
    {
        Auditoria::registar(
            Auditoria::CANAL_AUTH,
            'Terminou sessão no sistema.',
            autor: $this->autor($event->user),
        );
    }

    /**
     * Numa app de auditoria sanitária a tentativa falhada vale mais do que a
     * bem-sucedida: é o único sinal de acesso indevido.
     */
    public function handleFailed(Failed $event): void
    {
        Auditoria::registar(
            Auditoria::CANAL_AUTH,
            'Tentativa de início de sessão falhada.',
            ['identificador' => $this->identificador($event->credentials)],
            autor: $this->autor($event->user),
        );
    }

    public function handleLockout(Lockout $event): void
    {
        Auditoria::registar(
            Auditoria::CANAL_AUTH,
            'Conta bloqueada temporariamente por excesso de tentativas.',
            ['identificador' => $this->identificador($event->request->only('email'))],
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        Auditoria::registar(
            Auditoria::CANAL_AUTH,
            'Password redefinida.',
            autor: $this->autor($event->user),
        );
    }

    private function autor(?Authenticatable $user): ?Model
    {
        return $user instanceof Model ? $user : null;
    }

    /**
     * @param  array<string, mixed>  $credenciais
     */
    private function identificador(array $credenciais): string
    {
        $valor = $credenciais['email'] ?? $credenciais['name'] ?? null;

        return is_string($valor) && $valor !== ''
            ? Str::limit($valor, 120, '')
            : '(desconhecido)';
    }
}
