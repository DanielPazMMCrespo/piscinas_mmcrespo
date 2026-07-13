<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\DailyRecord;
use App\Models\FilterCheck;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PoolTest extends TestCase
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

    public function test_pool_has_many_daily_records(): void
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

        // Cria 3 registos
        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now()->subDay(),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'registado_em' => now()->subDays(2),
            'cloro_livre' => 1.0,
            'cloro_total' => 1.2,
            'ph' => 7.4,
            'temperatura' => 26.5,
            'transparencia' => 2,
        ]);

        $this->assertEquals(3, $pool->registosDiarios()->count());
    }

    public function test_pool_belongs_to_installation(): void
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

        $this->assertNotNull($pool->instalacao);
        $this->assertEquals($inst->id, $pool->instalacao->id);
        $this->assertEquals('Leiria', $pool->instalacao->name);
    }

    public function test_pool_temperature_limits_validated(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26.0,
            'temp_max' => 27.0,
            'volume' => 900,
            'active' => true,
        ]);

        $this->assertEquals(26.0, $pool->temp_min);
        $this->assertEquals(27.0, $pool->temp_max);

        // Atualiza os limites
        $pool->update(['temp_min' => 25.0, 'temp_max' => 30.0]);

        $this->assertEquals(25.0, $pool->temp_min);
        $this->assertEquals(30.0, $pool->temp_max);
    }

    public function test_pool_has_many_filter_checks(): void
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

        // Cria 2 verificações de filtro
        FilterCheck::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'verificado_em' => now(),
            'tipo_operacao' => 'lavagem',
        ]);

        FilterCheck::create([
            'pool_id' => $pool->id,
            'user_id' => $user->id,
            'verificado_em' => now()->subDay(),
            'tipo_operacao' => 'enxaguamento',
        ]);

        $this->assertEquals(2, $pool->verificacoesFiltro()->count());
    }

    public function test_pool_fillable_fields(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);

        $pool = Pool::create([
            'installation_id' => $inst->id,
            'name' => 'Lazer',
            'type' => 'Exterior',
            'temp_min' => 28.0,
            'temp_max' => 30.0,
            'volume' => 600.0,
            'active' => true,
        ]);

        $this->assertEquals($inst->id, $pool->installation_id);
        $this->assertEquals('Lazer', $pool->name);
        $this->assertEquals('Exterior', $pool->type);
        $this->assertEquals(600.0, $pool->volume);
        $this->assertTrue($pool->active);
    }

    public function test_pool_nome_completo_accessor(): void
    {
        $instLeiria = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $poolLeiria = Pool::create([
            'installation_id' => $instLeiria->id,
            'name' => 'Competição',
            'type' => 'Interior',
            'temp_min' => 26,
            'temp_max' => 27,
            'volume' => 900,
            'active' => true,
        ]);

        $this->assertEquals('Leiria Competição', $poolLeiria->nome_completo);
        $this->assertEquals('Leiria — Competição', $poolLeiria->nomeCompleto(' — '));

        $instMaceira = Installation::create(['name' => 'Maceira', 'morada' => 'Rua Y', 'active' => true]);
        $poolMaceira = Pool::create([
            'installation_id' => $instMaceira->id,
            'name' => 'Maceira',
            'type' => 'Polivalente',
            'temp_min' => 28,
            'temp_max' => 30,
            'volume' => 170,
            'active' => true,
        ]);

        $this->assertEquals('Maceira', $poolMaceira->nome_completo);
        $this->assertEquals('Maceira', $poolMaceira->nomeCompleto(' — '));
    }
}
