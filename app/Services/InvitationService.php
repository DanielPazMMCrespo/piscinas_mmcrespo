<?php declare(strict_types=1);

namespace App\Services;

use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class InvitationService
{
    public function send(string $email, string $role, User $invitedBy): UserInvitation
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

        $invitation = UserInvitation::create([
            'email'         => $email,
            'role'          => $role,
            'token'         => hash('sha256', $rawToken),
            'invited_by_id' => $invitedBy->id,
            'expires_at'    => now()->addHours(48),
        ]);

        Mail::to($email)->send(new UserInvitationMail($invitation, $rawToken));

        return $invitation;
    }

    public function accept(UserInvitation $invitation, array $data): User
    {
        $firstName = $data['first_name'];
        $lastName  = $data['last_name'];

        $user = User::create([
            'name'       => trim("{$firstName} {$lastName}"),
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'email'      => $invitation->email,
            'phone'      => $data['phone'] ?? null,
            'password'   => isset($data['password']) && $data['password'] !== ''
                ? Hash::make($data['password'])
                : null,
            'pin'        => isset($data['pin']) && $data['pin'] !== ''
                ? $data['pin']
                : null,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($invitation->role);

        $invitation->update(['accepted_at' => now()]);

        return $user;
    }
}
