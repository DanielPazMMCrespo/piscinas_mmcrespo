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

// Push dos timers de retrolavagem vencidos. Faz polling curto (~50s, ciclos de 5s)
// para latência baixa sem worker dedicado — mesmo padrão do queue:work acima.
Schedule::command('timers:fire-due')
    ->everyMinute()
    ->withoutOverlapping(10);


// Avisa admin+técnico de torneiras abertas há mais tempo que o limite configurado.
// Não precisa de precisão ao minuto — o limite é em horas.
Schedule::command('torneiras:verificar-abertas')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Resumo de conformidade nos horários configurados em Definições do Sistema
// (AppSetting 'digest_conformidade_horas'). Precisa de granularidade ao minuto
// para acertar o horário; o próprio comando faz o dedup por slot.
Schedule::command('notificacoes:resumo-conformidade')
    ->everyMinute()
    ->withoutOverlapping();

// Anúncios personalizados (Notificações Personalizadas) — únicos e diários.
// Precisa de granularidade ao minuto; o próprio comando faz o dedup.
Schedule::command('notificacoes:custom-fire-due')
    ->everyMinute()
    ->withoutOverlapping();

// ── Fase 1: Automação & Inteligência Operacional ────────────────────────────

// Regras de negócio automáticas: auto-incidentes (3x violação/dia/piscina),
// escalação de incidentes sem resposta >24h, fecho automático de stock.
Schedule::command('regras:executar')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Verificação de tendências degradantes nos parâmetros (pH, cloro).
// Cada 6h é suficiente — tendências são de longo prazo.
Schedule::command('tendencias:verificar')
    ->everySixHours()
    ->withoutOverlapping();

// Resumo operacional de fim de turno — horários configuráveis em Definições.
// Corre everyMinute; o comando faz dedup por slot (mesmo padrão do digest).
Schedule::command('notificacoes:resumo-turno')
    ->everyMinute()
    ->withoutOverlapping();

// Comparação semanal de conformidade — domingos às 09:00.
Schedule::command('notificacoes:comparacao-semanal')
    ->weeklyOn(0, '09:00')
    ->withoutOverlapping();

// ── Fase 3: Analytics & Business Intelligence ───────────────────────────────

// Relatório mensal automático (livro sanitário do mês anterior) — dia 1 às 06:00.
Schedule::command('relatorio:mensal-automatico')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping();
