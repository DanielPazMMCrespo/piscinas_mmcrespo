<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Notifications\NaoConformidadeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class NaoConformidadeWebPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_usa_canal_webpush(): void
    {
        $registo = new DailyRecord;
        $notification = new NaoConformidadeNotification($registo, ['pH 9.5'], 'Leiria Competição');

        $this->assertContains(WebPushChannel::class, $notification->via(new User));
    }

    public function test_payload_tem_titulo_corpo_e_url_da_listagem(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::factory()->create(['installation_id' => $inst->id, 'active' => true]);
        $registo = DailyRecord::factory()->create(['pool_id' => $pool->id]);

        $notification = new NaoConformidadeNotification($registo, ['pH 9.5'], 'Leiria Competição');
        $payload = $notification->toWebPush(new User, $notification)->toArray();

        $this->assertStringContainsString('Leiria Competição', $payload['title']);
        $this->assertStringContainsString('pH 9.5', $payload['body']);
        $this->assertSame("nao-conforme-{$registo->id}", $payload['tag']);
        // Não há página de edição/detalhe (livro append-only) — o link aponta para a listagem.
        $this->assertStringContainsString('/admin/daily-records', $payload['data']['url']);
    }
}
