<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HannaCloudService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A Hanna Cloud etiqueta o relógio local do controlador como UTC. Lido ao pé
 * da letra, cada leitura ficava uma hora no futuro no verão português e a
 * validação de plausibilidade do sync rejeitava-a — as 5 sondas pararam.
 */
class HannaHoraLeituraTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Lisbon']);
    }

    public function test_z_suffixed_timestamp_keeps_its_wall_clock_in_app_timezone(): void
    {
        $hora = HannaCloudService::horaLeitura('2026-08-03T16:40:46.000Z');

        $this->assertNotNull($hora);
        $this->assertSame('2026-08-03 16:40:46', $hora->toDateTimeString());
        $this->assertSame('Europe/Lisbon', $hora->timezoneName);
    }

    /** Um offset explícito não deve deslocar o relógio de parede enviado. */
    public function test_explicit_offset_keeps_its_wall_clock_too(): void
    {
        $hora = HannaCloudService::horaLeitura('2026-08-03T16:40:46+01:00');

        $this->assertSame('2026-08-03 16:40:46', $hora?->toDateTimeString());
    }

    public function test_reading_from_ten_minutes_ago_passes_the_plausibility_check(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 15:25:00', 'Europe/Lisbon'));

        // O controlador envia 15:12 local, etiquetado como UTC.
        $hora = HannaCloudService::horaLeitura('2026-08-26T15:12:00.000Z');

        $this->assertFalse(
            $hora->gt(now()->addMinutes(10)),
            'Uma leitura de 13 minutos atrás não pode ser considerada futura.',
        );
        $this->assertFalse($hora->lt(now()->subDays(30)));

        Carbon::setTestNow();
    }

    /** Um relógio genuinamente adiantado continua a ser rejeitado. */
    public function test_controller_clock_hours_ahead_is_still_implausible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 15:25:00', 'Europe/Lisbon'));

        $hora = HannaCloudService::horaLeitura('2026-08-26T17:12:00.000Z');

        $this->assertTrue($hora->gt(now()->addMinutes(10)));

        Carbon::setTestNow();
    }

    /**
     * O controlador da Lazer reporta sempre hora local + 2h. Com o ajuste
     * declarado, a leitura volta a cair na janela plausível.
     */
    public function test_negative_offset_brings_a_two_hour_ahead_clock_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 15:56:33', 'Europe/Lisbon'));

        $hora = HannaCloudService::horaLeitura('2026-08-26T17:45:31.000Z', -120);

        $this->assertSame('2026-08-26 15:45:31', $hora?->toDateTimeString());
        $this->assertFalse($hora->gt(now()->addMinutes(10)));

        Carbon::setTestNow();
    }

    /** Sem ajuste, o mesmo relógio adiantado continua a ser rejeitado. */
    public function test_same_clock_without_offset_stays_implausible(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-26 15:56:33', 'Europe/Lisbon'));

        $hora = HannaCloudService::horaLeitura('2026-08-26T17:45:31.000Z');

        $this->assertTrue($hora->gt(now()->addMinutes(10)));

        Carbon::setTestNow();
    }

    public function test_null_and_empty_return_null(): void
    {
        $this->assertNull(HannaCloudService::horaLeitura(null));
        $this->assertNull(HannaCloudService::horaLeitura(''));
        $this->assertNull(HannaCloudService::horaLeitura('   '));
    }
}
