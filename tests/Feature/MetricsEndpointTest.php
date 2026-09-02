<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `MetricsController::index()` chamava `Str::equals()`, que não existe no
 * Laravel — todo o pedido a /api/metrics dava 500 (Error fatal), nunca 401
 * nem 200. Este teste prova as três respostas esperadas depois da correção
 * para `hash_equals()`.
 */
class MetricsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_token_configurado_fecha_o_endpoint(): void
    {
        config(['services.metrics.token' => null]);

        $this->getJson('/api/metrics')
            ->assertStatus(401);
    }

    public function test_sem_token_no_pedido_e_recusado(): void
    {
        config(['services.metrics.token' => 'segredo-de-teste']);

        $this->getJson('/api/metrics')
            ->assertStatus(401);
    }

    public function test_token_errado_e_recusado(): void
    {
        config(['services.metrics.token' => 'segredo-de-teste']);

        $this->getJson('/api/metrics', [
            'Authorization' => 'Bearer token-errado',
        ])->assertStatus(401);
    }

    public function test_token_correto_devolve_as_metricas(): void
    {
        config(['services.metrics.token' => 'segredo-de-teste']);

        $this->getJson('/api/metrics', [
            'Authorization' => 'Bearer segredo-de-teste',
        ])
            ->assertStatus(200)
            ->assertJsonStructure([
                'timestamp',
                'piscinas' => ['total_ativas', 'total_encerradas', 'com_registo_hoje', 'detalhe'],
                'incidentes' => ['abertos', 'tempo_medio_resposta_minutos_30d'],
                'stock' => ['produtos_abaixo_do_minimo'],
            ]);
    }
}
