<?php declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Incident;
use App\Models\Installation;
use App\Models\User;
use App\Notifications\IncidentCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class IncidentWebPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_notification_uses_webpush_channel(): void
    {
        $notification = new IncidentCreatedNotification(new Incident());

        $this->assertContains(WebPushChannel::class, $notification->via(new User()));
    }

    public function test_incident_webpush_payload_has_title_body_and_url(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $reportante = User::factory()->create(['name' => 'Ana']);
        $incident = Incident::factory()->create([
            'installation_id' => $inst->id,
            'user_id' => $reportante->id,
            'descricao' => 'Fuga junto ao filtro',
        ]);

        $notification = new IncidentCreatedNotification($incident);
        $payload = $notification->toWebPush(new User(), $notification)->toArray();

        $this->assertSame('Novo incidente — Leiria', $payload['title']);
        $this->assertStringContainsString('Fuga junto ao filtro', $payload['body']);
        $this->assertSame("incident-{$incident->id}", $payload['tag']);
        $this->assertArrayHasKey('url', $payload['data']);
        $this->assertStringContainsString((string) $incident->id, $payload['data']['url']);
    }
}
