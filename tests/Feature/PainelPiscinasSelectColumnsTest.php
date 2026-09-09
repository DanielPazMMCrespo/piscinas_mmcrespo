<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\PainelPiscinasWidget;
use App\Models\DailyRecord;
use App\Models\Installation;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * O painel do dashboard restringe colunas nas queries de histórico. O SQLite
 * aceita um identificador desconhecido entre aspas duplas como literal de
 * texto (legacy DQS) e devolve valores nulos em silêncio; o PostgreSQL de
 * produção responde "column does not exist" e o dashboard passa a 500.
 * Estes dois testes cobrem os dois sintomas — o erro e o silêncio.
 */
class PainelPiscinasSelectColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Pool $piscina;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $instalacao = Installation::factory()->create(['active' => true]);
        $this->piscina = Pool::factory()->create(['installation_id' => $instalacao->id, 'active' => true]);

        Cache::flush();
    }

    public function test_historico_manual_alimenta_o_sparkline_de_ph(): void
    {
        // Três dias de histórico: o sparkline só é desenhado com 2+ pontos.
        foreach ([[3, 7.1], [2, 7.4], [0, 7.25]] as [$diasAtras, $ph]) {
            DailyRecord::factory()->create([
                'pool_id' => $this->piscina->id,
                'user_id' => $this->admin->id,
                'registado_em' => now()->subDays($diasAtras)->subHour(),
                'ph' => $ph,
                'cloro_livre' => 1.2,
                'temperatura' => 27.5,
            ]);
        }

        $this->actingAs($this->admin);

        $widget = new PainelPiscinasWidget;
        $metodo = new \ReflectionMethod($widget, 'buildPoolData');
        $dados = $metodo->invoke($widget);

        $item = collect($dados['piscinas'])->firstWhere(fn (array $i) => $i['piscina']->id === $this->piscina->id);

        $this->assertNotNull($item);
        $this->assertSame('7,25', $item['metricas4']['ph']['valor']);
        // Com uma coluna inexistente no select(), o valor vinha a null e o
        // sparkline saía vazio mesmo havendo registo no período.
        $this->assertNotEmpty($item['metricas4']['ph']['sparkline']);
    }

    public function test_colunas_selecionadas_no_painel_existem_na_base_de_dados(): void
    {
        $fonte = file_get_contents(app_path('Filament/Widgets/PainelPiscinasWidget.php'));

        $tabelas = [
            'sensor_readings' => Schema::getColumnListing('sensor_readings'),
            'daily_records' => Schema::getColumnListing('daily_records'),
        ];

        preg_match_all("/->select\(\s*(?:\[)?((?:\s*(?:'[a-z_]+'|\/\/[^\n]*)\s*,?\s*)+)\)/", $fonte, $matches);

        $this->assertNotEmpty($matches[1], 'Nenhum select() encontrado — o padrão do teste ficou desatualizado.');

        $desconhecidas = [];
        foreach ($matches[1] as $lista) {
            preg_match_all("/'([a-z_]+)'/", $lista, $colunas);

            foreach ($colunas[1] as $coluna) {
                if (! in_array($coluna, $tabelas['sensor_readings'], true)
                    && ! in_array($coluna, $tabelas['daily_records'], true)) {
                    $desconhecidas[] = $coluna;
                }
            }
        }

        $this->assertSame([], $desconhecidas, 'Colunas inexistentes num select() do painel (accessors não são colunas): '.implode(', ', $desconhecidas));
    }

    public function test_metricas_contem_limites_e_autor_da_analise_manual(): void
    {
        $tecnico = User::factory()->create(['name' => 'Carlos Silva']);

        DailyRecord::factory()->create([
            'pool_id' => $this->piscina->id,
            'user_id' => $tecnico->id,
            'registado_em' => now()->subHours(2),
            'ph' => 7.30,
            'cloro_livre' => 1.27,
            'cloro_total' => 1.48,
            'temperatura' => 28.5,
        ]);

        $this->actingAs($this->admin);

        $widget = new PainelPiscinasWidget;
        $metodo = new \ReflectionMethod($widget, 'buildPoolData');
        $dados = $metodo->invoke($widget);

        $item = collect($dados['piscinas'])->firstWhere(fn (array $i) => $i['piscina']->id === $this->piscina->id);

        $this->assertNotNull($item);
        dump($item['metricas4']['livre']);
        $this->assertSame('1,27 mg/L', $item['metricas4']['livre']['valor']);
        $this->assertFalse($item['metricas4']['livre']['ok']); // Alerta porque 1.27 > 1.20 a pH 7.30
        $this->assertSame('0,5–1,2', $item['metricas4']['livre']['limite_resumo']);
        $this->assertSame('Carlos Silva', $item['metricas4']['livre']['autor']);
        $this->assertSame('Carlos S.', $item['metricas4']['livre']['autor_curto']);
        $this->assertStringContainsString('0,5–1,2', $item['metricas4']['livre']['tooltip']);
        $this->assertStringContainsString('pH 7,30', $item['metricas4']['livre']['tooltip']);
        $this->assertStringContainsString('Carlos Silva', $item['metricas4']['livre']['tooltip']);

        $this->assertNotNull($item['ultimo_registo_manual']);
        $this->assertSame('Carlos Silva', $item['ultimo_registo_manual']['autor']);
    }
}
