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

// Processamento da fila 'daily-records' (desconto de stock + notificação de
// não-conformidade após criar um registo diário). Não existe um serviço Railway
// dedicado a queue:work — isto aproveita o scheduler já ativo para processar a
// fila a cada minuto. Se o volume crescer, considerar um worker dedicado
// (a imagem docker já suporta: docker-entrypoint.sh corre `php artisan queue:work`
// quando invocado com argumentos).
Schedule::command('queue:work --queue=daily-records,default --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();
