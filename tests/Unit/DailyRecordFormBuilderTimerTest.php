<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use PHPUnit\Framework\TestCase;

class DailyRecordFormBuilderTimerTest extends TestCase
{
    public function test_config_dos_timers_tem_as_duracoes_corretas(): void
    {
        $config = DailyRecordFormBuilder::timerRetrolavagemConfig();

        $this->assertSame([
            ['campo' => 'filtro_foto_retrolavagem', 'label' => 'Timer — Retrolavagem', 'duracaoSegundos' => 300],
            ['campo' => 'filtro_foto_enxaguamento', 'label' => 'Timer — Enxaguamento', 'duracaoSegundos' => 120],
        ], $config);
    }
}
