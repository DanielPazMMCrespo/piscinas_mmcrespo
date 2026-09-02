<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\FireDueTimersCommand;
use App\Models\TimerPush;
use App\Models\User;
use App\Notifications\TimerFinishedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Um timer de retrolavagem cujo registo nunca foi gravado deixava um TimerPush
 * pendente. Horas depois, na primeira vez que o comando corria, chegava um
 * aviso "a lavagem acabou" de uma lavagem que ninguem se lembra de ter feito.
 *
 * O mesmo acontecia quando o container esteve em baixo na altura de disparar.
 *
 * Um push de timer so vale nos minutos em volta do evento. Passado isso e
 * ruido, e e o ruido que faz a equipa deixar de confiar nas notificacoes.
 */
class TimerPushAtrasadoTest extends TestCase
{
    use RefreshDatabase;

    private function correr(): void
    {
        Artisan::call('timers:fire-due', ['--max-time' => 0, '--sleep' => 1]);
    }

    public function test_timer_muito_atrasado_nao_notifica(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $timer = TimerPush::create([
            'user_id' => $user->id,
            'fase' => 'lavagem',
            'fire_at' => now()->subMinutes(FireDueTimersCommand::TOLERANCIA_ATRASO_MIN + 5),
        ]);

        $this->correr();

        Notification::assertNothingSentTo($user);

        $timer->refresh();
        $this->assertNotNull($timer->cancelled_at, 'O timer velho tem de ficar dado como cancelado.');
        $this->assertNull($timer->sent_at, 'Nao foi enviado, logo nao pode ficar marcado como enviado.');
    }

    public function test_timer_atrasado_dentro_da_tolerancia_ainda_notifica(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $timer = TimerPush::create([
            'user_id' => $user->id,
            'fase' => 'lavagem',
            'fire_at' => now()->subMinutes(FireDueTimersCommand::TOLERANCIA_ATRASO_MIN - 5),
        ]);

        $this->correr();

        Notification::assertSentTo($user, TimerFinishedNotification::class);

        $timer->refresh();
        $this->assertNotNull($timer->sent_at);
        $this->assertNull($timer->cancelled_at);
    }

    /**
     * Um atraso de um minuto e o caso normal: o comando corre a cada minuto.
     */
    public function test_o_caso_normal_continua_a_notificar(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        TimerPush::create([
            'user_id' => $user->id,
            'fase' => 'enxaguamento',
            'fire_at' => now()->subSeconds(30),
        ]);

        $this->correr();

        Notification::assertSentTo($user, TimerFinishedNotification::class);
    }
}
