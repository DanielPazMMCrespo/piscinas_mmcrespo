<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource\Pages\CreateIncident;
use App\Models\Incident;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Fecha o buraco de autorização em Incident::creating(): o Select de
 * instalação/piscina em IncidentResource::form() só filtrava as opções
 * mostradas a um Nadador-Salvador — nunca validava no servidor o que era
 * submetido. Ver App\Models\Incident::autorizarPiscinaDoAutor().
 */
class IncidenteAutorizacaoPiscinaTest extends TestCase
{
    use RefreshDatabase;

    private Installation $instalacaoA;

    private Installation $instalacaoB;

    private Pool $piscinaA;

    private Pool $piscinaB;

    private User $ns;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        foreach (UserRole::all() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->instalacaoA = Installation::factory()->create(['name' => 'Leiria']);
        $this->piscinaA = Pool::factory()->create(['installation_id' => $this->instalacaoA->id]);

        $this->instalacaoB = Installation::factory()->create(['name' => 'Maceira']);
        $this->piscinaB = Pool::factory()->create(['installation_id' => $this->instalacaoB->id]);

        $this->ns = User::factory()->create();
        $this->ns->assignRole(UserRole::NADADOR_SALVADOR);
        $this->ns->piscinas()->attach($this->piscinaA->id);
    }

    /** @return array<string, mixed> */
    private function dadosIncidente(Installation $instalacao, ?Pool $piscina = null): array
    {
        return [
            'installation_id' => $instalacao->id,
            'pool_id' => $piscina?->id,
            'user_id' => $this->ns->id,
            'ocorreu_em' => now(),
            'type' => 'fuga_agua',
            'descricao' => 'Fuga junto ao filtro',
            'status' => 'aberto',
        ];
    }

    public function test_ns_nao_pode_criar_incidente_para_piscina_de_outra_instalacao(): void
    {
        $this->actingAs($this->ns);

        $excecao = null;

        try {
            Incident::create($this->dadosIncidente($this->instalacaoB, $this->piscinaB));
        } catch (HttpException $e) {
            $excecao = $e;
        }

        $this->assertNotNull($excecao, 'Esperava-se um HttpException 403.');
        $this->assertSame(403, $excecao->getStatusCode());
        $this->assertDatabaseMissing('incidents', ['pool_id' => $this->piscinaB->id]);
    }

    public function test_ns_nao_pode_criar_incidente_de_instalacao_inteira_para_instalacao_que_nao_e_sua(): void
    {
        $this->actingAs($this->ns);

        $excecao = null;

        try {
            Incident::create($this->dadosIncidente($this->instalacaoB));
        } catch (HttpException $e) {
            $excecao = $e;
        }

        $this->assertNotNull($excecao, 'Esperava-se um HttpException 403.');
        $this->assertSame(403, $excecao->getStatusCode());
        $this->assertDatabaseMissing('incidents', ['installation_id' => $this->instalacaoB->id]);
    }

    public function test_ns_continua_a_conseguir_criar_incidente_para_a_sua_propria_piscina(): void
    {
        $this->actingAs($this->ns);

        $incidente = Incident::create($this->dadosIncidente($this->instalacaoA, $this->piscinaA));

        $this->assertDatabaseHas('incidents', [
            'id' => $incidente->id,
            'pool_id' => $this->piscinaA->id,
            'installation_id' => $this->instalacaoA->id,
        ]);
    }

    public function test_ns_continua_a_conseguir_criar_incidente_de_instalacao_inteira_da_sua_instalacao(): void
    {
        $this->actingAs($this->ns);

        $incidente = Incident::create($this->dadosIncidente($this->instalacaoA));

        $this->assertDatabaseHas('incidents', [
            'id' => $incidente->id,
            'pool_id' => null,
            'installation_id' => $this->instalacaoA->id,
        ]);
    }

    public function test_fluxo_real_do_formulario_recusa_piscina_fora_do_alcance_do_ns(): void
    {
        $this->actingAs($this->ns);

        // O Livewire embrulha a exceção lançada dentro de call() numa resposta
        // (não a relança para o PHPUnit) — por isso a prova aqui é o estado:
        // nenhum incidente entra na base de dados para a piscina B.
        Livewire::test(CreateIncident::class)
            ->fillForm([
                'installation_id' => $this->instalacaoB->id,
                'pool_id' => $this->piscinaB->id,
                'user_id' => $this->ns->id,
                'ocorreu_em' => now(),
                'type' => 'fuga_agua',
                'descricao' => 'Fuga junto ao filtro',
            ])
            ->call('create');

        $this->assertDatabaseMissing('incidents', ['pool_id' => $this->piscinaB->id]);
    }

    public function test_edit_continua_bloqueado_para_ns_mesmo_no_seu_proprio_incidente(): void
    {
        $incidente = Incident::create($this->dadosIncidente($this->instalacaoA, $this->piscinaA));

        $this->assertFalse($this->ns->can('update', $incidente));
    }

    public function test_ns_nao_pode_ver_incidente_de_outro_utilizador_por_url(): void
    {
        $outroNs = User::factory()->create();
        $outroNs->assignRole(UserRole::NADADOR_SALVADOR);
        $outroNs->piscinas()->attach($this->piscinaB->id);

        $incidenteDeOutrem = Incident::create(array_merge(
            $this->dadosIncidente($this->instalacaoB, $this->piscinaB),
            ['user_id' => $outroNs->id]
        ));

        $this->assertFalse($this->ns->can('view', $incidenteDeOutrem));
    }
}
