<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização das sondas Hanna Cloud (BL132). Só corre se houver credenciais.
if (config('services.hanna.email')) {
    Schedule::command('hanna:sync')->everyFifteenMinutes()->withoutOverlapping();
}
