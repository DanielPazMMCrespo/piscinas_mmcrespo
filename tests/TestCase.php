<?php

namespace Tests;

use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Memos estaticos do formulario de registo diario vivem um pedido HTTP em
        // producao, mas a suite corre tudo num processo e o RefreshDatabase
        // reinicia os IDs das piscinas. Sem isto, o que um ficheiro de teste
        // memoiza para a "piscina 1" contamina o ficheiro seguinte.
        DailyRecordFormBuilder::limparMemos();
    }
}
