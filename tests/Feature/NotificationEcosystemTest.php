<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Pages\Definicoes;
use App\Models\HannaDevice;
use App\Models\Incident;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Notifications\HannaThresholdAlert;
use App\Notifications\IncidentCreatedNotification;
use App\Notifications\TendenciaAlertaNotification;
use App\Notifications\TimerFinishedNotification;
use App\Services\HannaCloudService;
use App\Services\SettingsService;
use App\Support\JanelaSilencio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NotificationEcosystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => UserRole::ADMIN]);
        Role::firstOrCreate(['name' => UserRole::TECNICO]);
        Role::firstOrCreate(['name' => UserRole::NADADOR_SALVADOR]);

        app(SettingsService::class)->flush();
        Cache::flush();
    }

    public function test_janela_silencio_identifica_corretamente_noite_e_domingo(): void
    {
        $janela = app(JanelaSilencio::class);

        // Quarta-feira às 14:00 (dia de semana, fora do silêncio)
        $quartaTarde = Carbon::parse('2026-09-09 14:00:00');
        $this->assertFalse($janela->ativa($quartaTarde));

        // Quarta-feira às 23:30 (noite, dentro do silêncio 22h-08h)
        $quartaNoite = Carbon::parse('2026-09-09 23:30:00');
        $this->assertTrue($janela->ativa($quartaNoite));

        // Quinta-feira às 05:00 (madrugada, dentro do silêncio 22h-08h)
        $quintaMadrugada = Carbon::parse('2026-09-10 05:00:00');
        $this->assertTrue($janela->ativa($quintaMadrugada));

        // Domingo às 15:00 (domingo o dia inteiro)
        $domingoTarde = Carbon::parse('2026-09-13 15:00:00');
        $this->assertTrue($janela->ativa($domingoTarde));
    }

    public function test_janela_silencio_pode_ser_desativada_nas_definicoes(): void
    {
        $settings = app(SettingsService::class);
        $settings->set('silencio_ativo', '0');

        $janela = app(JanelaSilencio::class);

        // Mesmo às 23:30, com silêncio desativado deve retornar false
        $quartaNoite = Carbon::parse('2026-09-09 23:30:00');
        $this->assertFalse($janela->ativa($quartaNoite));
    }

    public function test_wants_notification_bloqueia_push_durante_silencio_mas_permite_database_e_timers(): void
    {
        $installation = Installation::create(['name' => 'Leiria', 'active' => true]);
        $user = User::factory()->create();
        $incident = Incident::create([
            'installation_id' => $installation->id,
            'user_id' => $user->id,
            'type' => 'avaria',
            'descricao' => 'Fuga de água na casa das máquinas',
            'status' => 'aberto',
            'ocorreu_em' => now(),
        ]);
        $notif = new IncidentCreatedNotification($incident);

        // 1. Durante o dia
        Carbon::setTestNow(Carbon::parse('2026-09-09 14:00:00'));

        $this->assertTrue($user->wantsNotification('incident_created', 'push'));
        $channelsDia = $notif->via($user);
        $this->assertContains('database', $channelsDia);
        $this->assertContains(WebPushChannel::class, $channelsDia);

        // 2. Durante a noite (23:00)
        Carbon::setTestNow(Carbon::parse('2026-09-09 23:00:00'));

        // Push e mail bloqueados no repouso
        $this->assertFalse($user->wantsNotification('incident_created', 'push'));
        $this->assertFalse($user->wantsNotification('incident_created', 'mail'));

        // Sino na app continua a receber silenciosamente via database
        $channelsNoite = $notif->via($user);
        $this->assertContains('database', $channelsNoite);
        $this->assertNotContains(WebPushChannel::class, $channelsNoite);

        // Exceção vital: timer de retrolavagem chega ao push mesmo de noite
        $this->assertTrue($user->wantsNotification('timer_finished', 'push'));
        $timerNotif = new TimerFinishedNotification('retrolavagem');
        $this->assertContains(WebPushChannel::class, $timerNotif->via($user));

        Carbon::setTestNow();
    }

    public function test_notificacoes_usam_formato_nativo_do_filament_com_acoes(): void
    {
        $user = User::factory()->create();

        // 1. TimerFinishedNotification
        $timerNotif = new TimerFinishedNotification('retrolavagem', 'Piscina Olímpica');
        $dbTimer = $timerNotif->toDatabase($user);

        $this->assertArrayHasKey('title', $dbTimer);
        $this->assertArrayHasKey('actions', $dbTimer);
        $this->assertStringContainsString('Retrolavagem terminada', $dbTimer['title']);
        $this->assertNotEmpty($dbTimer['actions']);

        // 2. TendenciaAlertaNotification
        $tendenciaNotif = new TendenciaAlertaNotification(
            nomePiscina: 'Aprendizagem',
            parametro: 'ph',
            valores: [7.8, 7.9, 8.1],
            previsao: 8.3,
            limite: 8.0
        );
        $dbTendencia = $tendenciaNotif->toDatabase($user);

        $this->assertArrayHasKey('title', $dbTendencia);
        $this->assertArrayHasKey('actions', $dbTendencia);
        $this->assertStringContainsString('Tendência degradante', $dbTendencia['title']);
        $this->assertSame('/admin/analise-parametros', $dbTendencia['actions'][0]['url']);
    }

    public function test_hanna_cloud_sync_respeita_janela_de_silencio_e_cooldown_de_4_horas(): void
    {
        config(['services.hanna.email' => 'test@hanna.pt', 'services.hanna.password' => 'secret']);

        $installation = Installation::create(['name' => 'Leiria', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $installation->id,
            'name' => 'Piscina Teste',
            'type' => 'competition',
            'temp_min' => 26.0,
            'temp_max' => 28.0,
            'volume' => 500.0,
            'active' => true,
        ]);
        $device = HannaDevice::create([
            'hanna_device_id' => 'DEV-TEST-01',
            'name' => 'Sonda Teste',
            'pool_id' => $pool->id,
            'active' => true,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $currentReading = [
            'dt' => '2026-09-09 23:00:00',
            'ph' => 8.5,
            'orp' => 700.0,
            'temperatura_agua' => 26.5,
            'temperatura_ar' => 25.0,
            'caudal_ph' => 1.2,
            'caudal_cloro' => 0.8,
            'raw_parameters' => ['test' => true],
            'ph_overtime' => false,
            'ch_overtime' => false,
        ];

        $serviceMock = Mockery::mock(HannaCloudService::class);
        $serviceMock->shouldReceive('authenticate')->byDefault();
        $serviceMock->shouldReceive('getLastReading')
            ->with('DEV-TEST-01')
            ->andReturnUsing(function () use (&$currentReading) {
                return $currentReading;
            });
        $this->app->instance(HannaCloudService::class, $serviceMock);

        // Cenário 1: Durante a noite (23:00) -> NÃO deve enviar alerta de threshold
        Carbon::setTestNow(Carbon::parse('2026-09-09 23:00:00'));
        $currentReading['dt'] = '2026-09-09 23:00:00';
        Notification::fake();

        Artisan::call('hanna:sync');
        Notification::assertNothingSent();

        // Cenário 2: De manhã (10:00) -> DEVE enviar 1 alerta
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));
        $currentReading['dt'] = '2026-09-10 10:00:00';
        Notification::fake();

        Artisan::call('hanna:sync');
        Notification::assertSentTo($admin, HannaThresholdAlert::class, 1);

        // Cenário 3: 15 minutos depois (10:15) com mesmo pH 8.5 -> NÃO deve enviar (cooldown ativo)
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:15:00'));
        $currentReading['dt'] = '2026-09-10 10:15:00';
        Notification::fake();

        Artisan::call('hanna:sync');
        Notification::assertNothingSent();

        // Cenário 4: pH volta ao normal (7.4) -> Cooldown deve ser limpo
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:30:00'));
        $currentReading['dt'] = '2026-09-10 10:30:00';
        $currentReading['ph'] = 7.4;
        Artisan::call('hanna:sync');

        $this->assertFalse(Cache::has("hanna_threshold_cooldown_{$device->id}"));

        Carbon::setTestNow();
    }

    public function test_alert_housekeeping_poda_notificacoes_antigas_da_bd(): void
    {
        $user = User::factory()->create();

        // Notificação lida antiga (>30 dias) -> Deve ser apagada
        $idLidaAntiga = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $idLidaAntiga,
            'type' => 'App\Notifications\Teste',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Lida Antiga']),
            'read_at' => now()->subDays(35),
            'created_at' => now()->subDays(35),
            'updated_at' => now()->subDays(35),
        ]);

        // Notificação lida recente (5 dias) -> Deve ser mantida
        $idLidaRecente = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $idLidaRecente,
            'type' => 'App\Notifications\Teste',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Lida Recente']),
            'read_at' => now()->subDays(5),
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        // Notificação não lida antiga (>60 dias) -> Deve ser apagada
        $idNaoLidaAntiga = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $idNaoLidaAntiga,
            'type' => 'App\Notifications\Teste',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Não Lida Antiga']),
            'read_at' => null,
            'created_at' => now()->subDays(65),
            'updated_at' => now()->subDays(65),
        ]);

        // Notificação não lida recente (10 dias) -> Deve ser mantida
        $idNaoLidaRecente = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $idNaoLidaRecente,
            'type' => 'App\Notifications\Teste',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => 'Não Lida Recente']),
            'read_at' => null,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        Artisan::call('alerts:housekeeping');

        $this->assertDatabaseMissing('notifications', ['id' => $idLidaAntiga]);
        $this->assertDatabaseMissing('notifications', ['id' => $idNaoLidaAntiga]);
        $this->assertDatabaseHas('notifications', ['id' => $idLidaRecente]);
        $this->assertDatabaseHas('notifications', ['id' => $idNaoLidaRecente]);
    }

    public function test_utilizador_pode_limpar_notificacoes_lidas_do_sino(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $id1 = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id1,
            'type' => 'App\Notifications\Teste',
            'notifiable_type' => User::class,
            'notifiable_id' => $user1->id,
            'data' => json_encode(['title' => 'Lida User 1']),
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id2 = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $id2,
            'type' => 'App\Notifications\Teste',
            'notifiable_type' => User::class,
            'notifiable_id' => $user2->id,
            'data' => json_encode(['title' => 'Lida User 2']),
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user1);
        Livewire::test(Definicoes::class)
            ->call('limparMinhasNotificacoesLidas');

        $this->assertDatabaseMissing('notifications', ['id' => $id1]);
        $this->assertDatabaseHas('notifications', ['id' => $id2]);
    }
}
