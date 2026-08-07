<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\JanelaSilencio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class JanelaSilencioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'gestor', 'tecnico'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    private function janela(): JanelaSilencio
    {
        app(SettingsService::class)->flush();

        return app(JanelaSilencio::class);
    }

    public function test_janela_padrao_cobre_a_noite_e_atravessa_a_meia_noite(): void
    {
        $janela = $this->janela();

        // Quarta-feira.
        $this->assertTrue($janela->ativa(Carbon::parse('2026-08-05 22:00')));
        $this->assertTrue($janela->ativa(Carbon::parse('2026-08-05 23:59')));
        $this->assertTrue($janela->ativa(Carbon::parse('2026-08-05 00:01')));
        $this->assertTrue($janela->ativa(Carbon::parse('2026-08-05 07:59')));

        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-05 08:00')));
        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-05 14:00')));
        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-05 21:59')));
    }

    public function test_domingo_esta_em_silencio_o_dia_inteiro(): void
    {
        $domingo = Carbon::parse('2026-08-09 12:00');
        $this->assertTrue($domingo->isSunday());

        $this->assertTrue($this->janela()->ativa($domingo));
    }

    public function test_domingo_pode_ser_desligado_sem_desligar_a_noite(): void
    {
        AppSetting::create(['key' => 'silencio_domingo', 'value' => false, 'group' => 'geral', 'label' => 'Silencio Domingo', 'type' => 'string']);

        $janela = $this->janela();

        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-09 12:00')));
        $this->assertTrue($janela->ativa(Carbon::parse('2026-08-09 23:00')));
    }

    public function test_janela_desativada_nao_silencia_nada(): void
    {
        AppSetting::create(['key' => 'silencio_ativo', 'value' => false, 'group' => 'geral', 'label' => 'Silencio Ativo', 'type' => 'string']);

        $janela = $this->janela();

        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-05 03:00')));
        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-09 12:00')));
    }

    public function test_horarios_personalizados_sem_atravessar_a_meia_noite(): void
    {
        AppSetting::create(['key' => 'silencio_inicio', 'value' => '13:00', 'group' => 'geral', 'label' => 'Inicio', 'type' => 'string']);
        AppSetting::create(['key' => 'silencio_fim', 'value' => '15:00', 'group' => 'geral', 'label' => 'Fim', 'type' => 'string']);

        $janela = $this->janela();

        $this->assertTrue($janela->ativa(Carbon::parse('2026-08-05 13:30')));
        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-05 12:59')));
        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-05 15:00')));
        $this->assertFalse($janela->ativa(Carbon::parse('2026-08-05 03:00')));
    }

    public function test_push_e_mail_ficam_cortados_dentro_da_janela(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->travelTo(Carbon::parse('2026-08-05 03:00'));

        // Conformidade obrigatória também é cortada — a informação fica no sino.
        $this->assertFalse($admin->wantsNotification('nao_conformidade', 'push'));
        $this->assertFalse($admin->wantsNotification('nao_conformidade', 'mail'));
        $this->assertFalse($admin->wantsNotification('incident_created', 'push'));

        // O temporizador é iniciado pelo próprio utilizador: continua a avisar.
        $this->assertTrue($admin->wantsNotification('timer_finished', 'push'));

        $this->travelTo(Carbon::parse('2026-08-05 10:00'));

        $this->assertTrue($admin->wantsNotification('nao_conformidade', 'push'));
        $this->assertTrue($admin->wantsNotification('incident_created', 'push'));
    }

    public function test_notificacao_continua_a_ser_gravada_na_base_de_dados(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->travelTo(Carbon::parse('2026-08-05 03:00'));

        $canais = (new \App\Notifications\NaoConformidadeNotification(
            new \App\Models\DailyRecord,
            ['pH 9,1 acima do máximo'],
            'Leiria Lazer',
        ))->via($admin);

        $this->assertSame(['database'], $canais);
    }
}
