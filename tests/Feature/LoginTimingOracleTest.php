<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTimingOracleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sem um Hash::check "a sério" no ramo de utilizador inexistente, a resposta
     * fica mensuravelmente mais rápida do que com password errada — permite
     * enumerar contas por timing. Provamos aqui a contrapartida verificável em
     * CI (o Hash::check acontece), não a diferença de tempo em si (instável).
     */
    public function test_hash_check_e_executado_mesmo_quando_o_email_nao_existe(): void
    {
        Hash::shouldReceive('check')
            ->once()
            ->andReturn(false);

        try {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'ninguem@test.pt', 'password' => 'palavra-errada'])
                ->call('authenticate');
        } catch (ValidationException) {
            // esperado: o login falha, o que importa é o Hash::check ter corrido
        }
    }
}
