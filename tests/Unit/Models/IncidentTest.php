<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Incident;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class IncidentTest extends TestCase
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

    public function test_incident_status_transitions_from_aberto_to_resolvido(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create();
        $resolver = User::factory()->create();

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'Bomba avariada',
            'descricao' => 'Bomba não liga',
            'status' => 'aberto',
        ]);

        $this->assertEquals('aberto', $incidente->status);
        $this->assertFalse($incidente->estaResolvido());

        // Resolve o incidente
        $incidente->update([
            'status' => 'resolvido',
            'resolvido_em' => now(),
            'resolvido_por' => $resolver->id,
            'resolucao' => 'Bomba reparada',
        ]);

        $this->assertEquals('resolvido', $incidente->status);
        $this->assertTrue($incidente->estaResolvido());
    }

    public function test_resolved_incident_timestamp_recorded(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create();
        $resolver = User::factory()->create();

        $agora = now();
        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => $agora,
            'type' => 'Filtro entupido',
            'descricao' => 'Filtro precisa limpeza',
            'status' => 'aberto',
        ]);

        $this->assertNull($incidente->resolvido_em);

        $incidente->update([
            'status' => 'resolvido',
            'resolvido_em' => $agora->addHours(2),
            'resolvido_por' => $resolver->id,
            'resolucao' => 'Filtro limpo',
        ]);

        $this->assertNotNull($incidente->resolvido_em);
        $this->assertTrue($incidente->resolvido_em->isAfter($agora));
        $this->assertEquals($resolver->id, $incidente->resolvido_por);
    }

    public function test_incident_belongs_to_installation(): void
    {
        $inst = Installation::create(['name' => 'Maceira', 'morada' => 'Rua Y', 'active' => true]);
        $user = User::factory()->create();

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'Vidro partido',
            'status' => 'aberto',
        ]);

        $this->assertNotNull($incidente->instalacao);
        $this->assertEquals($inst->id, $incidente->instalacao->id);
        $this->assertEquals('Maceira', $incidente->instalacao->name);
    }

    public function test_incident_belongs_to_user(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create(['name' => 'João']);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'Avaria',
            'status' => 'aberto',
        ]);

        $this->assertNotNull($incidente->utilizador);
        $this->assertEquals($user->id, $incidente->utilizador->id);
        $this->assertEquals('João', $incidente->utilizador->name);
    }

    public function test_incident_resolver_relationship(): void
    {
        $inst = Installation::create(['name' => 'Leiria', 'morada' => 'Rua X', 'active' => true]);
        $user = User::factory()->create(['name' => 'João']);
        $resolver = User::factory()->create(['name' => 'Maria']);

        $incidente = Incident::create([
            'installation_id' => $inst->id,
            'user_id' => $user->id,
            'ocorreu_em' => now(),
            'type' => 'Avaria',
            'status' => 'resolvido',
            'resolvido_em' => now(),
            'resolvido_por' => $resolver->id,
            'resolucao' => 'Reparado',
        ]);

        $this->assertNotNull($incidente->resolvidoPor);
        $this->assertEquals($resolver->id, $incidente->resolvidoPor->id);
        $this->assertEquals('Maria', $incidente->resolvidoPor->name);
    }
}
