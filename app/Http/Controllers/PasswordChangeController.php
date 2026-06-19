<?php declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function show(): View
    {
        return view('auth.first-access');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = auth()->user();

        if (! $user?->must_change_password) {
            return redirect('/admin');
        }

        $validated = $request->validate([
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'pin'                   => ['nullable', 'digits_between:4,6'],
        ], [
            'password.required'     => 'A palavra-passe é obrigatória.',
            'password.min'          => 'Mínimo 8 caracteres.',
            'password.confirmed'    => 'As palavras-passe não coincidem.',
            'pin.digits_between'    => 'O PIN deve ter entre 4 e 6 dígitos.',
        ]);

        // Bloqueio: não deixar usar a password padrão
        if (Hash::check('piscinasmmcrespo26', $validated['password'])) {
            return back()
                ->withErrors(['password' => 'Não pode utilizar a password padrão. Escolha uma palavra-passe pessoal.'])
                ->withInput();
        }

        $user->update([
            'password'              => Hash::make($validated['password']),
            'pin'                   => isset($validated['pin']) && $validated['pin'] !== ''
                ? Hash::make($validated['pin'])
                : $user->pin,
            'must_change_password'  => false,
        ]);

        return redirect('/admin')->with('success', 'Palavra-passe alterada com sucesso. Bem-vindo(a)!');
    }
}
