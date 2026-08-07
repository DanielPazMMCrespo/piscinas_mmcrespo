<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class LoginRateLimitPerAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sem a chave de rate-limit incluir o e-mail, todas as tentativas de login
     * partilham o mesmo balde (chave = componente+método+IP) — 5 falhas contra
     * uma conta bloqueavam o login de qualquer outra conta a partir do mesmo IP.
     */
    public function test_falhas_de_login_de_uma_conta_nao_bloqueiam_outra_conta(): void
    {
        $vitima = User::factory()->create([
            'email' => 'vitima@test.pt',
            'password' => Hash::make('password-correta'),
        ]);

        $atacante = 'inexistente@test.pt';

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->fillForm(['email' => $atacante, 'password' => 'tentativa-errada'])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
        }

        // A conta-alvo (vítima) continua a conseguir entrar com a password certa.
        Livewire::test(Login::class)
            ->fillForm(['email' => 'vitima@test.pt', 'password' => 'password-correta'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($vitima);
    }
}
