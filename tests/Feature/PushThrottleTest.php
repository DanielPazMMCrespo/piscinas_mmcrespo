<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * A app chama os endpoints de push sozinha, muito mais do que um humano.
 *
 * `resources/js/push.js` sincroniza a subscricao em cada `window load`, e este
 * painel nao tem SPA mode: e uma chamada por pagina visitada. O timer chama em
 * iniciar, parar e reiniciar.
 *
 * Com um limite de 5/min um tecnico a passar pela sidebar levava 429. E as duas
 * chamadas em push.js acabam em `.catch(() => {})`, logo a recusa era invisivel:
 * o tecnico nao recebia o aviso do fim da lavagem e nunca sabia porque.
 */
class PushThrottleTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');
    }

    /**
     * Dez páginas visitadas num minuto é um técnico normal a trabalhar.
     */
    public function test_dez_sincronizacoes_de_subscricao_seguidas_passam(): void
    {
        $this->actingAs($this->tecnico);

        for ($i = 1; $i <= 10; $i++) {
            $resposta = $this->postJson('/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/exemplo-'.$i,
                'keys' => ['p256dh' => str_repeat('a', 87), 'auth' => str_repeat('b', 22)],
            ]);

            $this->assertNotSame(
                429,
                $resposta->getStatusCode(),
                "Pedido {$i} a /push/subscribe levou 429. O limite esta abaixo do que a propria app pede."
            );
        }
    }

    /**
     * Iniciar, parar e reiniciar o cronometro em três piscinas chega aqui.
     */
    public function test_dez_registos_de_timer_seguidos_passam(): void
    {
        $this->actingAs($this->tecnico);

        for ($i = 1; $i <= 10; $i++) {
            $resposta = $this->postJson('/push/timer', [
                'seconds' => 180,
                'fase' => 'lavagem',
            ]);

            $this->assertNotSame(
                429,
                $resposta->getStatusCode(),
                "Pedido {$i} a /push/timer levou 429. Um tecnico a mexer no cronometro passa disto."
            );
        }
    }

    public function test_dez_cancelamentos_de_timer_seguidos_passam(): void
    {
        $this->actingAs($this->tecnico);

        for ($i = 1; $i <= 10; $i++) {
            $resposta = $this->deleteJson('/push/timer', ['fase' => 'lavagem']);

            $this->assertNotSame(429, $resposta->getStatusCode(), "Pedido {$i} levou 429.");
        }
    }
}
