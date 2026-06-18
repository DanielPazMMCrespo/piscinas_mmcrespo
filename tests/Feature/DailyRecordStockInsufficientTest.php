<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DailyRecordStockInsufficientTest extends TestCase
{
    private User $user;
    private Installation $installation;
    private Pool $pool;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Cria utilizador técnico
        $this->user = User::factory()->create();
        $this->user->assignRole('tecnico');

        // Cria instalação + piscina
        $this->installation = Installation::factory()->create(['name' => 'Leiria']);
        $this->pool = Pool::factory()->create([
            'installation_id' => $this->installation->id,
            'name' => 'Competição',
        ]);

        // Cria produto
        $this->product = Product::factory()->create(['name' => 'Cloro em Pó']);

        // Cria stock na instalação (3 L disponível)
        StockInstallation::create([
            'installation_id' => $this->installation->id,
            'product_id' => $this->product->id,
            'quantity' => 3.0,
            'limite_minimo' => 1.0,
        ]);
    }

    public function test_registo_diario_desconta_stock_total_quando_disponivel(): void
    {
        // Técnico tenta adicionar 2 L de cloro (< 3 disponível)
        $data = [
            'pool_id' => $this->pool->id,
            'registado_em' => now(),
            'ph' => 7.4,
            'cloro_livre' => 0.8,
            'cloro_total' => 1.5,
            'temperatura' => 27.0,
            'turbidez' => 0.5,
            'agua_modo' => 'normal',
            'adicoes' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 2.0,
                    'acao_corretiva' => null,
                ],
            ],
        ];

        // Submete via Livewire/HTTP
        $this->actingAs($this->user)
            ->post(route('filament.admin.resources.daily-records.create'), $data)
            ->assertSuccessful();

        // Verifica registo criado
        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->pool->id,
            'ph' => 7.4,
        ]);

        // Verifica stock decrementado: 3.0 - 2.0 = 1.0
        $stock = StockInstallation::where('installation_id', $this->installation->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertSame(1.0, $stock->quantity);
    }

    public function test_registo_diario_desconta_stock_parcial_quando_insuficiente(): void
    {
        // Técnico tenta adicionar 10 L de cloro (> 3 disponível)
        $data = [
            'pool_id' => $this->pool->id,
            'registado_em' => now(),
            'ph' => 7.4,
            'cloro_livre' => 0.8,
            'cloro_total' => 1.5,
            'temperatura' => 27.0,
            'turbidez' => 0.5,
            'agua_modo' => 'normal',
            'adicoes' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10.0,
                    'acao_corretiva' => null,
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('filament.admin.resources.daily-records.create'), $data)
            ->assertSuccessful();

        // Verifica registo criado (não foi bloqueado)
        $this->assertDatabaseHas('daily_records', [
            'pool_id' => $this->pool->id,
        ]);

        // Verifica stock decrementado até zero: min(10.0, 3.0) = 3.0
        $stock = StockInstallation::where('installation_id', $this->installation->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertSame(0.0, $stock->quantity);
    }

    public function test_aviso_enviado_ao_técnico_quando_stock_insuficiente(): void
    {
        // Mock do Notification facade
        Notification::fake();

        $data = [
            'pool_id' => $this->pool->id,
            'registado_em' => now(),
            'ph' => 7.4,
            'cloro_livre' => 0.8,
            'cloro_total' => 1.5,
            'temperatura' => 27.0,
            'turbidez' => 0.5,
            'agua_modo' => 'normal',
            'adicoes' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10.0,
                    'acao_corretiva' => null,
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('filament.admin.resources.daily-records.create'), $data);

        // Verifica se notificação warning foi enviada
        Notification::assertSentTimes(fn ($notification) => str_contains(
            $notification->getMessage() ?? '',
            'Stock insuficiente'
        ), 1);
    }

    public function test_log_de_stock_insuficiente_criado(): void
    {
        Log::fake();

        $data = [
            'pool_id' => $this->pool->id,
            'registado_em' => now(),
            'ph' => 7.4,
            'cloro_livre' => 0.8,
            'cloro_total' => 1.5,
            'temperatura' => 27.0,
            'turbidez' => 0.5,
            'agua_modo' => 'normal',
            'adicoes' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => 10.0,
                    'acao_corretiva' => null,
                ],
            ],
        ];

        $this->actingAs($this->user)
            ->post(route('filament.admin.resources.daily-records.create'), $data);

        // Verifica log warning
        Log::assertLogged('warning', function ($message, $context) {
            return str_contains($message, 'Stock insuficiente') &&
                   isset($context['requested']) &&
                   isset($context['available']);
        });
    }

    public function test_registo_sem_adicoes_nao_afeta_stock(): void
    {
        $stockAntes = StockInstallation::where('installation_id', $this->installation->id)
            ->where('product_id', $this->product->id)
            ->first()
            ->quantity;

        $data = [
            'pool_id' => $this->pool->id,
            'registado_em' => now(),
            'ph' => 7.4,
            'cloro_livre' => 0.8,
            'cloro_total' => 1.5,
            'temperatura' => 27.0,
            'turbidez' => 0.5,
            'agua_modo' => 'normal',
            // sem adicoes
        ];

        $this->actingAs($this->user)
            ->post(route('filament.admin.resources.daily-records.create'), $data);

        $stockDepois = StockInstallation::where('installation_id', $this->installation->id)
            ->where('product_id', $this->product->id)
            ->first()
            ->quantity;

        // Stock não deve mudar
        $this->assertSame($stockAntes, $stockDepois);
    }
}
