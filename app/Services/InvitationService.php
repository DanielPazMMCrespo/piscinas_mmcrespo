<?php declare(strict_types=1);

namespace App\Services;

use App\Constants\UserRole;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class InvitationService
{
    public function __construct(
        private SettingsService $settings
    ) {}

    /**
     * @param array<int, int|string> $poolIds Piscinas a pré-atribuir (só aplicadas a Nadador Salvador).
     */
    public function send(string $email, string $role, User $invitedBy, array $poolIds = []): UserInvitation
    {
        if (User::where('email', $email)->exists()) {
            throw new RuntimeException("Já existe um utilizador com o email {$email}.");
        }

        $existing = UserInvitation::where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            throw new RuntimeException("Já existe um convite pendente para {$email}. Expira em {$existing->expires_at->format('d/m H:i')}.");
        }

        $rawToken = Str::random(64);
        $validadeHoras = $this->settings->getInt('convite_validade_horas', 48);

        $invitation = UserInvitation::create([
            'email'         => $email,
            'role'          => $role,
            'pool_ids'      => $role === UserRole::NADADOR_SALVADOR
                ? array_values(array_map('intval', $poolIds))
                : null,
            'token'         => hash('sha256', $rawToken),
            'invited_by_id' => $invitedBy->id,
            'expires_at'    => now()->addHours($validadeHoras),
        ]);

        Mail::to($email)->send(new UserInvitationMail($invitation, $rawToken));

        return $invitation;
    }

    public function accept(UserInvitation $invitation, array $data): User
    {
        $firstName = $data['first_name'];
        $lastName  = $data['last_name'];

        $password = isset($data['password']) && $data['password'] !== ''
            ? Hash::make($data['password'])
            : Hash::make(Str::random(32));

        $user = User::create([
            'name'       => trim("{$firstName} {$lastName}"),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $invitation->email,
            'phone'      => $data['phone'] ?? null,
            'password'   => $password,
            'pin'        => isset($data['pin']) && $data['pin'] !== ''
                ? $data['pin']
                : null,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $user->assignRole($invitation->role);

        if (! empty($invitation->pool_ids)) {
            $user->piscinas()->sync($invitation->pool_ids);
        }

        $invitation->update(['accepted_at' => now()]);

        return $user;
    }
}
