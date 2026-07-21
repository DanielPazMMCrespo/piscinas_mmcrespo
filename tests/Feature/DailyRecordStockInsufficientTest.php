<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Exercita o fluxo real de criação de registo diário (CreateDailyRecord via Livewire)
 * e o desconto de stock (CreateDailyRecord::descontarStock) executado em afterCreate.
 */
class DailyRecordStockInsufficientTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Installation $installation;
    private Pool $pool;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->assignRole('tecnico');

        $this->installation = Installation::factory()->create(['name' => 'Leiria']);
        $this->pool = Pool::factory()->create([
            'installation_id' => $this->installation->id,
            'name' => 'Competição',
        ]);

        $this->product = Product::factory()->create(['name' => 'Cloro em Pó', 'unidade' => 'L']);

        // Stock na instalação: 3 L disponível.
        StockInstallation::create([
            'installation_id' => $this->installation->id,
            'product_id' => $this->product->id,
            'quantity' => 3.0,
            'limite_minimo' => 0,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $adicoes
     */
    private function criarRegisto(array $adicoes): void
    {
        $poolData = [
            'bomba_ferrada' => true,
            'ph' => 7.4,
            'cloro_livre' => 0.8,
            'cloro_total' => 1.5,
            'temperatura' => 27.0,
            'transparencia' => 1,
            'ns_ph' => 7.2,
            'ns_cloro_livre' => 0.8,
            'ns_cloro_total' => 1.5,
            'ns_temperatura' => 27.0,
        ];

        if ($adicoes !== []) {
            $poolData['adicoes'] = $adicoes;
        }

        $estado = [
            'installation_id' => $this->pool->installation_id,
            'registado_em' => now(),
            'ns_foto' => [\Illuminate\Http\UploadedFile::fake()->create('ns_foto.jpg', 10)],
            'pools' => [
                $this->pool->id => $poolData,
            ]
        ];

        Livewire::actingAs($this->user)
            ->test(CreateDailyRecord::class)
            ->fillForm($estado)
            ->call('create')
            ->assertHasNoFormErrors();
    }

    private function stockAtual(): float
    {
        return (float) StockInstallation::where('installation_id', $this->installation->id)
            ->where('product_id', $this->product->id)
            ->first()
            ->quantity;
    }

    public function test_registo_diario_desconta_stock_total_quando_disponivel(): void
    {
        $this->criarRegisto([
            ['product_id' => $this->product->id, 'quantity' => 2.0],
        ]);

        $this->assertDatabaseHas('daily_records', ['pool_id' => $this->pool->id]);
        // 3.0 - 2.0 = 1.0
        $this->assertSame(1.0, $this->stockAtual());
    }

    public function test_registo_e_criado_e_stock_desconta_ate_zero_quando_pedido_excede_disponivel(): void
    {
        // Quantidade pedida (10.0) excede o disponível (3.0): o formulário não bloqueia
        // (o técnico pode adicionar stock ali mesmo ou gravar o registo na mesma); o
        // backend desconta até zero em vez de rejeitar o registo.
        $this->criarRegisto([
            ['product_id' => $this->product->id, 'quantity' => 10.0],
        ]);

        $this->assertDatabaseHas('daily_records', ['pool_id' => $this->pool->id]);
        $this->assertSame(0.0, $this->stockAtual());
    }

    public function test_log_de_consumo_criado(): void
    {
        $this->criarRegisto([
            ['product_id' => $this->product->id, 'quantity' => 2.0],
        ]);

        $stock = StockInstallation::where('installation_id', $this->installation->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertDatabaseHas('stock_installation_logs', [
            'stock_installation_id' => $stock->id,
            'tipo_movimento' => 'consumo',
        ]);
    }

    public function test_registo_sem_adicoes_nao_afeta_stock(): void
    {
        $stockAntes = $this->stockAtual();

        $this->criarRegisto([]);

        $this->assertSame($stockAntes, $this->stockAtual());
        $this->assertDatabaseCount('stock_installation_logs', 0);
    }
}
