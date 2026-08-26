<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Guarda contra o regresso do mutex órfão de 24 horas.
 *
 * withoutOverlapping() sem argumento usa 1440 minutos. Se o comando for morto
 * a meio (reinício do contentor Railway, OOM, deploy), o mutex fica no cache e
 * o comando é ignorado em silêncio durante um dia — sem log nem auditoria.
 */
class ScheduleMutexTest extends TestCase
{
    /** Nenhum comando agendado pode ficar trancado durante horas a fio. */
    private const MAX_MUTEX_MINUTOS = 60;

    public function test_every_scheduled_command_has_a_bounded_mutex(): void
    {
        $eventos = app(Schedule::class)->events();

        $this->assertNotEmpty($eventos, 'Nenhum comando agendado encontrado em routes/console.php.');

        foreach ($eventos as $evento) {
            if (! $evento->withoutOverlapping) {
                continue;
            }

            $this->assertLessThanOrEqual(
                self::MAX_MUTEX_MINUTOS,
                $evento->expiresAt,
                "[{$evento->command}] tem um mutex de {$evento->expiresAt} min. "
                .'Passa um valor explícito a withoutOverlapping() que cubra só o pior tempo de execução.',
            );
        }
    }

    public function test_hanna_sync_mutex_is_shorter_than_its_interval(): void
    {
        config(['services.hanna.email' => 'test@hanna.pt']);

        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'hanna:sync'));

        if ($evento === null) {
            $this->markTestSkipped('hanna:sync não está agendado (sem HANNA_CLOUD_EMAIL).');
        }

        // O sync corre a cada 15 min: um mutex mais longo perderia ciclos.
        $this->assertLessThan(15, $evento->expiresAt);
    }
}
