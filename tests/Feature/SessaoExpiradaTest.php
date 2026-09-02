<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sessao expirada tem de dar login, nao 500.
 *
 * As rotas fora do painel Filament (/primeiro-acesso, /piscinas-encerradas, e
 * os endpoints de push e offline-sync) estao atras do middleware `auth` do
 * Laravel. Sem sessao, esse middleware chama `route('login')` — e nao existe
 * nenhuma rota com esse nome neste projeto: o painel usa
 * `filament.admin.auth.login` e o atalho /login e anonimo.
 *
 * Resultado: RouteNotFoundException, ou seja 500. Quem tem a sessao morta e
 * abre um link guardado, ou o nadador-salvador que e desviado para
 * /piscinas-encerradas depois de a sessao cair, leva um erro do servidor em
 * vez do formulario de entrada.
 */
class SessaoExpiradaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function rotasAutenticadas(): array
    {
        return [
            'primeiro acesso' => ['/primeiro-acesso'],
            'piscinas encerradas' => ['/piscinas-encerradas'],
        ];
    }

    #[DataProvider('rotasAutenticadas')]
    public function test_rota_autenticada_sem_sessao_manda_para_o_login(string $rota): void
    {
        $this->get($rota)->assertRedirect('/admin/login');
    }

    #[DataProvider('rotasAutenticadas')]
    public function test_rota_autenticada_sem_sessao_nao_da_erro_de_servidor(string $rota): void
    {
        $resposta = $this->get($rota);

        $this->assertLessThan(
            500,
            $resposta->getStatusCode(),
            "{$rota} devolveu {$resposta->getStatusCode()} sem sessao. Devia mandar para o login."
        );
    }

    /**
     * Os endpoints de escrita fazem o mesmo caminho. Um dispositivo com a
     * sessao expirada a tentar sincronizar offline nao pode receber 500 — tem
     * de saber que precisa de voltar a entrar.
     */
    public function test_endpoints_de_escrita_sem_sessao_nao_dao_erro_de_servidor(): void
    {
        foreach (['/push/subscribe', '/push/timer', '/offline-sync/daily-records'] as $rota) {
            $resposta = $this->postJson($rota, []);

            $this->assertLessThan(
                500,
                $resposta->getStatusCode(),
                "{$rota} devolveu {$resposta->getStatusCode()} sem sessao."
            );
        }
    }
}
