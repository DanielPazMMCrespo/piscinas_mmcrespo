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

// Backup automático da base de dados: diário às 03:00, sem overlap, output em log.
Schedule::command('backup:database')->dailyAt('03:00')->withoutOverlapping()->runInBackground();

// Arquivamento semanal de registos diários com mais de 1 ano para a tabela de arquivo.
// Corre todos os domingos às 04:00.
Schedule::command('archive:daily-records --older-than=365')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping()
    ->runInBackground();

// Processamento da fila 'daily-records', 'sensor-sync' e 'default' a cada minuto.
// Não existe um serviço Railway dedicado a queue:work — isto aproveita o scheduler já ativo
// para processar as filas. Se o volume crescer, considerar um worker dedicado.
Schedule::command('queue:work --queue=daily-records,sensor-sync,default --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
