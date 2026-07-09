<?php

declare(strict_types=1);

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Jobs\ProcessDailyRecordAfterCreate;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\RecordAddition;
use App\Models\StockInstallation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
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
        'temp_min' => 26.0,
        'temp_max' => 30.0,
        'volume' => 900,
    ]);
    $this->product = Product::factory()->create(['name' => 'Cloro em Pó', 'unidade' => 'L']);

    // Stock na instalação: 3.000 L disponível.
    $this->stock = StockInstallation::create([
        'installation_id' => $this->installation->id,
        'product_id' => $this->product->id,
        'quantity' => 3.000,
        'limite_minimo' => 0,
    ]);
});

test('o job processa fotos, desconta stock e abre alerta de torneira no caminho feliz', function (): void {
    $record = DailyRecord::factory()->create([
        'pool_id' => $this->pool->id,
        'user_id' => $this->user->id,
        'analises_fotos' => ['analises/a.jpg'],
        'agua_modo' => 'on_com_agua',
    ]);

    RecordAddition::factory()->create([
        'daily_record_id' => $record->id,
        'product_id' => $this->product->id,
        'quantity' => 2.000,
    ]);

    // Testa o job real — sem simular a transação de stock à mão.
    (new ProcessDailyRecordAfterCreate($record->id, $this->user->id))->handle();

    // Stock descontado pelo job (3.000 - 2.000 = 1.000).
    expect((float) $this->stock->fresh()->quantity)->toBe(1.0);

    $this->assertDatabaseHas('stock_installation_logs', [
        'stock_installation_id' => $this->stock->id,
        'tipo_movimento' => 'consumo',
        'quantity' => 2.000,
        'user_id' => $this->user->id,
    ]);

    $this->assertDatabaseHas('record_photos', [
        'daily_record_id' => $record->id,
        'path' => 'analises/a.jpg',
    ]);

    $this->assertDatabaseHas('tap_alerts', [
        'pool_id' => $this->pool->id,
        'opened_record_id' => $record->id,
        'resolved_at' => null,
    ]);
});

test('a validação crítica de stock impede a criação do registo e o despacho do job', function (): void {
    Queue::fake();

    Livewire::actingAs($this->user)
        ->test(CreateDailyRecord::class)
        ->fillForm([
            'installation_id' => $this->pool->installation_id,
            'pools' => [
                $this->pool->id => [
                    'adicoes' => [
                        ['product_id' => $this->product->id, 'quantity' => 10.000],
                    ],
                ]
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(["pools.{$this->pool->id}.adicoes.0.quantity"]);

    $this->assertDatabaseCount('daily_records', 0);
    expect((float) $this->stock->fresh()->quantity)->toBe(3.0);

    Queue::assertNotPushed(ProcessDailyRecordAfterCreate::class);
});
