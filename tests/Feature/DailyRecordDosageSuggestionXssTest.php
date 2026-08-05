<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use App\Filament\Resources\DailyRecordResource\Pages\CreateDailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionProperty;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyRecordDosageSuggestionXssTest extends TestCase
{
    use RefreshDatabase;

    /**
     * DailyRecordFormBuilder guarda estado em propriedades static ($sondaMemo,
     * $ultimoRegistoMemo, ...) sem hook de reset (bug pré-existente, fora do
     * âmbito desta correção) — sem isto, montar o wizard aqui deixa memo válido
     * para o pool_id usado, que fica servido (errado) a outros testes que
     * reusem o mesmo id depois deste.
     */
    protected function tearDown(): void
    {
        $resets = [
            'sondaMemo' => [],
            'sondaMomentoMemo' => [],
            'modoRapido' => false,
            'poolFixo' => null,
            'ultimoRegistoMemo' => [],
        ];

        foreach ($resets as $prop => $valorInicial) {
            $reflection = new ReflectionProperty(DailyRecordFormBuilder::class, $prop);
            $reflection->setAccessible(true);
            $reflection->setValue(null, $valorInicial);
        }

        parent::tearDown();
    }

    public function test_nome_de_produto_malicioso_e_escapado_na_sugestao_de_dosagem(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'tecnico', 'guard_name' => 'web']);

        $tecnico = User::factory()->create();
        $tecnico->assignRole('tecnico');

        $installation = Installation::factory()->create();
        $pool = Pool::factory()->create([
            'installation_id' => $installation->id,
            'temp_min' => 26.0,
            'temp_max' => 28.0,
        ]);

        $payload = '<script>alert(document.cookie)</script>';

        Product::create([
            'name' => $payload,
            'unidade' => 'L',
            'categoria' => 'Correção pH',
            'active' => true,
        ]);

        $test = Livewire::actingAs($tecnico)
            ->test(CreateDailyRecord::class)
            ->fillForm([
                'installation_id' => $installation->id,
                'pools' => [
                    $pool->id => [
                        'ns_ph' => 5.0, // abaixo do mínimo legal, dispara sugestão de dosagem
                    ],
                ],
            ]);

        $findPlaceholder = fn () => collect($test->instance()->getForm('form')->getFlatComponents(withHidden: true))
            ->first(fn ($c) => str_contains((string) $c->getId(), 'sugestao_dosagem_banner'));

        $placeholder = $findPlaceholder();
        $this->assertNotNull($placeholder);

        // O closure do banner chama $get("pools.{id}.ns_ph") de dentro de um Fieldset
        // cujo statePath já é "pools.{id}" — resolve para um path duplicado que nunca
        // existe, por isso o banner nunca aparece na app real (bug funcional à parte,
        // fora do âmbito desta correção). Escrevemos o valor no path que o código
        // efetivamente resolve, só para conseguir exercitar o closure de escaping.
        $resolvedPath = $placeholder->generateRelativeStatePath("pools.{$pool->id}.ns_ph", false);
        $test->set($resolvedPath, 5.0);

        $placeholder = $findPlaceholder();
        $html = $placeholder->getContent()->toHtml();

        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString(e($payload), $html);
    }
}
