<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserInvitation;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function handleRedirect(string $token): RedirectResponse
    {
        $invitation = UserInvitation::findValid($token);
        if (! $invitation) {
            session()->flash('invitation_error', 'Este convite expirou ou já foi utilizado.');
        } else {
            session(['invitation_token' => $token]);
        }

        return redirect()->route('invitation.form');
    }

    public function show(Request $request): View
    {
        $token = session('invitation_token');
        $invitation = $token ? UserInvitation::findValid($token) : null;
        $error = session('invitation_error');

        return view('auth.accept-invitation', [
            'invitation' => $invitation,
            'expired' => $invitation === null,
            'token' => $token,
            'error' => $error,
        ]);
    }

    public function store(Request $request, InvitationService $service): RedirectResponse
    {
        $token = $request->input('token') ?: session('invitation_token');

        if (! $token) {
            return redirect()->route('invitation.form')->withErrors(['token' => 'Token de convite em falta.']);
        }

        $invitation = UserInvitation::findValid($token);

        if (! $invitation) {
            return redirect()->route('invitation.form')->withErrors(['token' => 'Este convite expirou ou já foi utilizado.']);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'pin' => ['nullable', 'digits_between:4,6'],
        ], [
            'first_name.required' => 'O primeiro nome é obrigatório.',
            'last_name.required' => 'O último nome é obrigatório.',
            'password.min' => 'A palavra-passe deve ter pelo menos 8 caracteres.',
            'pin.digits_between' => 'O PIN deve ter entre 4 e 6 dígitos.',
        ]);

        if (empty($validated['password']) && empty($validated['pin'])) {
            return back()
                ->withErrors(['password' => 'Deve definir uma palavra-passe ou um PIN (ou ambos).'])
                ->withInput();
        }

        $user = $service->accept($invitation, $validated);

        session()->forget('invitation_token');

        Auth::login($user);

        return redirect('/admin')->with('success', 'Bem-vindo(a)! A sua conta foi criada com sucesso.');
    }
}
