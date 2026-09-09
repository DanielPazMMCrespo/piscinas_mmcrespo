<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regressao legal: o enxaguamento e a reposicao em posicao normal sao etapas de
 * uma retrolavagem. Quando os cards verticais substituiram as Tabs, a condicao
 * de visibilidade ficou pelo caminho e estes campos passaram a aparecer sempre.
 * Como `filtro_foto_enxaguamento` e `filtro_foto_posicao_normal` estao na
 * whitelist do DailyRecordService, o tecnico podia gravar a prova de um
 * enxaguamento que nunca aconteceu no livro sanitario.
 */
class DailyRecordEnxaguamentoVisibilityTest extends TestCase
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
        ]);
    }

    public function test_enxaguamento_esta_escondido_sem_retrolavagem(): void
    {
        $prefixo = "pools.{$this->competicao->id}";

        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                "{$prefixo}.filtro_faz_retrolavagem" => false,
            ])
            ->assertFormFieldIsHidden("{$prefixo}.timer_lavagem")
            ->assertFormFieldIsHidden("{$prefixo}.timer_enxaguamento");
    }

    public function test_enxaguamento_aparece_com_retrolavagem_ligada(): void
    {
        $prefixo = "pools.{$this->competicao->id}";

        Livewire::actingAs($this->tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $this->leiria->id,
                "{$prefixo}.filtro_faz_retrolavagem" => true,
            ])
            ->assertFormFieldIsVisible("{$prefixo}.timer_lavagem")
            ->assertFormFieldIsVisible("{$prefixo}.timer_enxaguamento");
    }
}
