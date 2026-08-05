<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use App\Services\DailyRecordService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyRecordServiceFieldWhitelistTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_do_cliente_nao_sobrepoe_campos_calculados_no_servidor(): void
    {
        $autor = User::factory()->create();
        $vitima = User::factory()->create();

        $installation = Installation::factory()->create();
        $pool = Pool::factory()->create(['installation_id' => $installation->id]);

        $registoAlvo = DailyRecord::create([
            'pool_id' => $pool->id,
            'user_id' => $vitima->id,
            'registado_em' => now()->subDay(),
            'ph' => 7.2,
        ]);

        app(DailyRecordService::class)->createRecords($autor, [
            'registado_em' => now()->toDateString(),
            'pools' => [
                $pool->id => [
                    'ph' => 7.4,
                    // campos que um cliente malicioso tentaria forjar
                    'user_id' => $vitima->id,
                    'e_correcao' => true,
                    'corrige_registo_id' => $registoAlvo->id,
                    'razao_correcao' => 'forjado pelo cliente',
                ],
            ],
        ]);

        $criado = DailyRecord::where('pool_id', $pool->id)->where('user_id', '!=', $vitima->id)->firstOrFail();

        $this->assertSame($autor->id, $criado->user_id);
        $this->assertFalse((bool) $criado->e_correcao);
        $this->assertNull($criado->corrige_registo_id);
        $this->assertNull($criado->razao_correcao);
        $this->assertEquals(7.4, (float) $criado->ph);
    }
}
