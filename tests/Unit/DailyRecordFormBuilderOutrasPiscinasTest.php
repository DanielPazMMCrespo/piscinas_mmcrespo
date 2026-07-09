<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use App\Models\Installation;
use App\Models\Pool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyRecordFormBuilderOutrasPiscinasTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_outras_piscinas_ativas_da_mesma_instalacao(): void
    {
        $leiria = Installation::factory()->create(['name' => 'Leiria']);
        $competicao = Pool::factory()->create(['installation_id' => $leiria->id, 'name' => 'Competição', 'active' => true]);
        $lazer = Pool::factory()->create(['installation_id' => $leiria->id, 'name' => 'Lazer', 'active' => true]);
        $infantil = Pool::factory()->create(['installation_id' => $leiria->id, 'name' => 'Infantil', 'active' => true]);

        $resultado = DailyRecordFormBuilder::outrasPiscinasDaInstalacao($competicao->id);

        $this->assertSame([
            $infantil->id => 'Infantil',
            $lazer->id => 'Lazer',
        ], $resultado);
    }

    public function test_lista_vazia_para_piscina_unica_na_instalacao(): void
    {
        $maceira = Installation::factory()->create(['name' => 'Maceira']);
        $pool = Pool::factory()->create(['installation_id' => $maceira->id, 'name' => 'Maceira', 'active' => true]);

        $resultado = DailyRecordFormBuilder::outrasPiscinasDaInstalacao($pool->id);

        $this->assertSame([], $resultado);
    }

    public function test_lista_vazia_quando_pool_id_nulo(): void
    {
        $this->assertSame([], DailyRecordFormBuilder::outrasPiscinasDaInstalacao(null));
    }
}
