<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TestPush;
use App\Models\User;
use App\Notifications\TestPushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class TestPushCommandTest extends TestCase
{
    use RefreshDatabase;

    private function correr(): void
    {
        Artisan::call('notificacoes:teste-fire-due', ['--max-time' => 0, '--sleep' => 1]);
    }

    public function test_due_test_push_notifies_user_and_is_marked_sent(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $teste = TestPush::create([
            'user_id' => $user->id,
            'tipo' => 'incidente',
            'fire_at' => now()->subSecond(),
        ]);

        $this->correr();

        Notification::assertSentTo($user, TestPushNotification::class);
        $this->assertNotNull($teste->fresh()->sent_at);
    }

    public function test_future_and_already_sent_pushes_are_ignored(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        TestPush::create(['user_id' => $user->id, 'tipo' => 'timer', 'fire_at' => now()->addMinutes(5)]);
        TestPush::create(['user_id' => $user->id, 'tipo' => 'torneira', 'fire_at' => now()->subSecond(), 'sent_at' => now()]);

        $this->correr();

        Notification::assertNothingSent();
    }

    public function test_notification_uses_webpush_channel_and_valid_tipos(): void
    {
        $notification = new TestPushNotification('resumo');
        $this->assertContains(WebPushChannel::class, $notification->via(new User()));

        $this->assertSame(
            ['incidente', 'mensagem', 'timer', 'fora_limites', 'torneira', 'resumo'],
            TestPushNotification::tiposValidos()
        );
    }

    public function test_titulo_e_corpo_customizados_substituem_os_defaults(): void
    {
        $notification = new TestPushNotification('incidente', 'Título editado', 'Mensagem editada');
        $payload = $notification->toWebPush(new User(), $notification)->toArray();

        $this->assertSame('Título editado', $payload['title']);
        $this->assertSame('Mensagem editada', $payload['body']);
    }

    public function test_sem_override_usa_defaults_do_tipo(): void
    {
        $defaults = TestPushNotification::defaults('torneira');
        $notification = new TestPushNotification('torneira');
        $payload = $notification->toWebPush(new User(), $notification)->toArray();

        $this->assertSame($defaults['title'], $payload['title']);
        $this->assertSame($defaults['body'], $payload['body']);
    }
}
