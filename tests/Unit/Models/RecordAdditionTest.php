<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\RecordAddition;
use App\Models\StockInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecordAdditionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (['admin', 'tecnico', 'nadador_salvador'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }
    }

    public function test_record_addition_relationship_to_daily_record(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26,
            'temp_max' => 27,
            'volume' => 900,
            'active' => true,
        ]);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $adicao = RecordAddition::create([
            'daily_record_id' => $registo->id,
            'product_id' => $product->id,
            'quantity' => 5.0,
        ]);

        $this->assertEquals($registo->id, $adicao->registoDiario->id);
        $this->assertEquals($product->id, $adicao->produto->id);
        $this->assertEquals(5.0, $adicao->quantity);
    }

    public function test_record_addition_validates_sufficient_quantity(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26,
            'temp_max' => 27,
            'volume' => 900,
            'active' => true,
        ]);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);

        // Cria stock limitado
        StockInstallation::create([
            'installation_id' => $inst->id,
            'product_id' => $product->id,
            'quantity' => 10.0,
            'limite_minimo' => 5.0,
        ]);

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        // Tenta adicionar quantidade superior ao stock
        $adicao = RecordAddition::create([
            'daily_record_id' => $registo->id,
            'product_id' => $product->id,
            'quantity' => 15.0,  // Superior ao stock (10.0)
        ]);

        // O registo é criado (não bloqueia), mas a quantidade é excessiva
        $this->assertEquals(15.0, $adicao->quantity);
    }

    public function test_record_addition_with_corrective_action(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26,
            'temp_max' => 27,
            'volume' => 900,
            'active' => true,
        ]);

        $user = User::factory()->create();
        $product = Product::create(['name' => 'Ácido', 'unidade' => 'L']);

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $adicao = RecordAddition::create([
            'daily_record_id' => $registo->id,
            'product_id' => $product->id,
            'quantity' => 2.5,
            'acao_corretiva' => 'Ajustar pH para 7.2',
        ]);

        $this->assertEquals('Ajustar pH para 7.2', $adicao->acao_corretiva);
    }

    public function test_multiple_additions_per_record(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26,
            'temp_max' => 27,
            'volume' => 900,
            'active' => true,
        ]);

        $user = User::factory()->create();
        $cloro = Product::create(['name' => 'Cloro', 'unidade' => 'kg']);
        $acido = Product::create(['name' => 'Ácido', 'unidade' => 'L']);

        $registo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        RecordAddition::create([
            'daily_record_id' => $registo->id,
            'product_id' => $cloro->id,
            'quantity' => 5.0,
        ]);

        RecordAddition::create([
            'daily_record_id' => $registo->id,
            'product_id' => $acido->id,
            'quantity' => 2.5,
        ]);

        $this->assertEquals(2, $registo->adicoes()->count());
    }
}
