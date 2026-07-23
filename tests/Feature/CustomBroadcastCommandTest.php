<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Models\CustomBroadcast;
use App\Models\User;
use App\Notifications\CustomBroadcastNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CustomBroadcastCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Hora fixa a meio do dia para os testes de agendamento diário serem
        // determinísticos (evita wrap à meia-noite na aritmética de horas).
        Carbon::setTestNow(Carbon::parse('2026-07-23 10:00:00'));

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function correr(): void
    {
        Artisan::call('notificacoes:custom-fire-due');
    }

    public function test_envio_unico_vencido_notifica_cargos_escolhidos_e_marca_enviado(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);
        $gestor = User::factory()->create();
        $gestor->assignRole(UserRole::GESTOR);
        $tecnico = User::factory()->create();
        $tecnico->assignRole(UserRole::TECNICO);

        $broadcast = CustomBroadcast::create([
            'titulo' => 'Manutenção agendada',
            'corpo' => 'A piscina fecha às 18h para manutenção.',
            'cargos' => [UserRole::ADMIN, UserRole::GESTOR],
            'tipo_agendamento' => CustomBroadcast::TIPO_UNICO,
            'enviar_em' => now()->subMinute(),
        ]);

        $this->correr();

        Notification::assertSentTo($admin, CustomBroadcastNotification::class);
        Notification::assertSentTo($gestor, CustomBroadcastNotification::class);
        Notification::assertNotSentTo($tecnico, CustomBroadcastNotification::class);
        $this->assertNotNull($broadcast->fresh()->enviado_em);
    }

    public function test_envio_unico_nao_dispara_duas_vezes(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        CustomBroadcast::create([
            'titulo' => 'Aviso', 'corpo' => 'Texto', 'cargos' => [UserRole::ADMIN],
            'tipo_agendamento' => CustomBroadcast::TIPO_UNICO, 'enviar_em' => now()->subMinute(),
        ]);

        $this->correr();
        $this->correr();

        Notification::assertSentToTimes($admin, CustomBroadcastNotification::class, 1);
    }

    public function test_envio_unico_futuro_nao_dispara(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        CustomBroadcast::create([
            'titulo' => 'Aviso', 'corpo' => 'Texto', 'cargos' => [UserRole::ADMIN],
            'tipo_agendamento' => CustomBroadcast::TIPO_UNICO, 'enviar_em' => now()->addHour(),
        ]);

        $this->correr();

        Notification::assertNothingSent();
    }

    public function test_diario_dispara_na_hora_certa_uma_vez_por_dia(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        $agora = now();

        CustomBroadcast::create([
            'titulo' => 'Bom dia', 'corpo' => 'Lembrete diário', 'cargos' => [UserRole::ADMIN],
            'tipo_agendamento' => CustomBroadcast::TIPO_DIARIO,
            'hora_diaria' => $agora->format('H:i'),
            'ativo' => true,
        ]);

        $this->correr();
        Notification::assertSentToTimes($admin, CustomBroadcastNotification::class, 1);

        // Correr de novo no mesmo dia/minuto não duplica.
        $this->correr();
        Notification::assertSentToTimes($admin, CustomBroadcastNotification::class, 1);
    }

    public function test_diario_pausado_nao_dispara(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        CustomBroadcast::create([
            'titulo' => 'Bom dia', 'corpo' => 'Lembrete diário', 'cargos' => [UserRole::ADMIN],
            'tipo_agendamento' => CustomBroadcast::TIPO_DIARIO,
            'hora_diaria' => now()->format('H:i'),
            'ativo' => false,
        ]);

        $this->correr();

        Notification::assertNothingSent();
    }

    public function test_diario_fora_de_hora_nao_dispara(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        CustomBroadcast::create([
            'titulo' => 'Bom dia', 'corpo' => 'Lembrete diário', 'cargos' => [UserRole::ADMIN],
            'tipo_agendamento' => CustomBroadcast::TIPO_DIARIO,
            'hora_diaria' => now()->addHours(3)->format('H:i'), // 13:00 > 10:00 (frozen)
            'ativo' => true,
        ]);

        $this->correr();

        Notification::assertNothingSent();
    }

    public function test_diario_dispara_apos_hora_agendada_se_minuto_exato_falhou(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->assignRole(UserRole::ADMIN);

        // Hora agendada já passou hoje (o minuto exato foi "perdido" pelo scheduler),
        // mas ainda não foi enviado. A janela de tolerância deve recuperar o envio.
        CustomBroadcast::create([
            'titulo' => 'Bom dia', 'corpo' => 'Lembrete diário', 'cargos' => [UserRole::ADMIN],
            'tipo_agendamento' => CustomBroadcast::TIPO_DIARIO,
            'hora_diaria' => now()->subMinutes(10)->format('H:i'), // 09:50 <= 10:00
            'ativo' => true,
        ]);

        $this->correr();
        Notification::assertSentToTimes($admin, CustomBroadcastNotification::class, 1);

        // Não duplica no mesmo dia.
        $this->correr();
        Notification::assertSentToTimes($admin, CustomBroadcastNotification::class, 1);
    }
}
