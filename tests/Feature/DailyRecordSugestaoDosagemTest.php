<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * O banner de sugestao automatica de dose nunca aparecia.
 *
 * O placeholder vive dentro da Section com statePath("pools.{id}"), mas o
 * `visible()` lia `$get("pools.{id}.ns_ph")` — caminho absoluto dentro de um
 * container que ja tem esse prefixo. Resolvia para pools.1.pools.1.ns_ph e
 * devolvia null sem erro, logo a condicao era sempre falsa.
 *
 * E a mesma armadilha da regra 9 do CLAUDE.md que manteve o enxaguamento
 * escondido durante meses. Aqui o efeito era o oposto: uma funcionalidade que
 * poupa trabalho ao tecnico estava desligada e ninguem reparou.
 *
 * Os helpers assertFormFieldIs* do Filament nao servem aqui: um Placeholder
 * estende Component e nao Field, logo nao aparece em getFlatFields().
 */
class DailyRecordSugestaoDosagemTest extends TestCase
{
    use RefreshDatabase;

    private User $tecnico;

    private Installation $leiria;

    private Pool $competicao;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $this->tecnico = User::factory()->create();
        $this->tecnico->assignRole('tecnico');

        $this->leiria = Installation::factory()->create(['name' => 'Leiria']);
        $this->competicao = Pool::factory()->create([
            'installation_id' => $this->leiria->id,
            'name' => 'Competição',
            'volume' => 900.0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $leituras
     */
    private function bannerEstaVisivel(array $leituras): bool
    {
        $pagina = Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm(array_merge(['installation_id' => $this->leiria->id], $leituras))
            ->instance();

        $nome = "sugestao_dosagem_banner_{$this->competicao->id}";

        foreach ($pagina->getForm('form')->getFlatComponents(withHidden: true) as $componente) {
            if ($componente instanceof Placeholder && $componente->getName() === $nome) {
                return $componente->isVisible();
            }
        }

        $this->fail("O placeholder [{$nome}] nao existe no formulario.");
    }

    public function test_banner_aparece_depois_de_o_tecnico_escrever_o_ph(): void
    {
        $this->assertTrue(
            $this->bannerEstaVisivel(["pools.{$this->competicao->id}.ns_ph" => 8.4]),
            'Com o pH preenchido o banner de sugestao de dose tem de aparecer.'
        );
    }

    public function test_banner_aparece_depois_de_o_tecnico_escrever_o_cloro(): void
    {
        $this->assertTrue(
            $this->bannerEstaVisivel(["pools.{$this->competicao->id}.ns_cloro_livre" => 0.2]),
            'Com o cloro preenchido o banner de sugestao de dose tem de aparecer.'
        );
    }

    public function test_banner_fica_escondido_sem_leituras(): void
    {
        $this->assertFalse(
            $this->bannerEstaVisivel([]),
            'Sem leituras nao ha dose a sugerir.'
        );
    }
}
