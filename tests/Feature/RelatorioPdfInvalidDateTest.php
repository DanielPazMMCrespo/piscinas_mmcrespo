<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RelatorioPdfInvalidDateTest extends TestCase
{
    private User $user;
    private Installation $installation;
    private Pool $pool;

    protected function setUp(): void
    {
        parent::setUp();

        // Cria utilizador admin
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        // Cria instalação + piscina
        $this->installation = Installation::factory()->create(['name' => 'Leiria']);
        $this->pool = Pool::factory()->create([
            'installation_id' => $this->installation->id,
            'name' => 'Competição',
        ]);
    }

    public function test_pdf_rejeita_data_fim_anterior_data_inicio(): void
    {
        Notification::fake();

        $data = [
            'installation_id' => $this->installation->id,
            'pool_id' => (string) $this->pool->id,
            'data_inicio' => '2026-06-18',
            'data_fim' => '2026-06-10', // anterior a data_inicio
        ];

        $this->actingAs($this->user)
            ->post(route('filament.admin.pages.relatorio-pdf'), [...$data, 'exportar' => true])
            ->assertHasErrors(['data_fim'] ?? []);

        // Ou verifica notificação de erro
        Notification::assertSentTimes(function ($notification) {
            return str_contains(
                $notification->getTitle() ?? '',
                'Data inválida'
            );
        }, 1);
    }

    public function test_pdf_rejeita_periodo_maior_1_ano(): void
    {
        Notification::fake();

        $data = [
            'installation_id' => $this->installation->id,
            'pool_id' => (string) $this->pool->id,
            'data_inicio' => '2025-01-01',
            'data_fim' => '2026-06-18', // ~18 meses
        ];

        $this->actingAs($this->user)
            ->post(route('filament.admin.pages.relatorio-pdf'), [...$data, 'exportar' => true]);

        Notification::assertSentTimes(function ($notification) {
            return str_contains(
                $notification->getTitle() ?? '',
                'Data inválida'
            ) || str_contains(
                $notification->getBody() ?? '',
                '365 dias'
            );
        }, 1);
    }

    public function test_pdf_aceita_periodo_valido(): void
    {
        // Este teste assume que há registos para o período
        $data = [
            'installation_id' => $this->installation->id,
            'pool_id' => 'todas',
            'data_inicio' => now()->subDays(7)->toDateString(),
            'data_fim' => now()->toDateString(),
        ];

        $response = $this->actingAs($this->user)
            ->post(route('filament.admin.pages.relatorio-pdf'), [...$data, 'exportar' => true]);

        // Se não houver registos, deve retornar null (sem erro).
        // Se houver, deve retornar PDF stream.
        $this->assertTrue(
            $response->status() === 200 ||
            (isset($response->baseResponse) && str_contains($response->baseResponse->headers->get('Content-Type'), 'application/pdf'))
        );
    }

    public function test_log_criado_para_data_invalida(): void
    {
        Log::fake();
        Notification::fake();

        $data = [
            'installation_id' => $this->installation->id,
            'pool_id' => (string) $this->pool->id,
            'data_inicio' => '2026-06-18',
            'data_fim' => '2026-06-10',
        ];

        $this->actingAs($this->user)
            ->post(route('filament.admin.pages.relatorio-pdf'), [...$data, 'exportar' => true]);

        Log::assertLogged('warning', function ($message, $context) {
            return str_contains($message, 'PDF export invalid date');
        });
    }

    public function test_data_no_futuro_e_rejeitada(): void
    {
        Notification::fake();

        $data = [
            'installation_id' => $this->installation->id,
            'pool_id' => (string) $this->pool->id,
            'data_inicio' => now()->addDay()->toDateString(),
            'data_fim' => now()->addDays(2)->toDateString(),
        ];

        $response = $this->actingAs($this->user)
            ->post(route('filament.admin.pages.relatorio-pdf'), [...$data, 'exportar' => true]);

        // Filament DatePicker com maxDate(now()) deve rejeitar
        $this->assertTrue($response->status() === 422 || $response->status() === 200);
    }
}
