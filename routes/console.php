<?php

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
 * NOTA SOBRE withoutOverlapping($minutos):
 *
 * O argumento é OBRIGATÓRIO em todos os comandos aqui. Sem ele, o Laravel usa
 * 1440 minutos (24 horas): se um comando for morto a meio (reinício do
 * contentor Railway, OOM, deploy), o mutex fica órfão e o comando é ignorado
 * EM SILÊNCIO durante um dia inteiro — sem log, sem erro, sem entrada na
 * auditoria. Já aconteceu ao hanna:sync, com as sondas paradas 24h.
 *
 * O valor deve cobrir o pior tempo de execução realista e nada mais.
 */

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sincronização das sondas Hanna Cloud (BL132). Só corre se houver credenciais.
if (config('services.hanna.email')) {
    Schedule::command('hanna:sync')->everyFifteenMinutes()->withoutOverlapping(10);
}

// Backup automático da base de dados: diário às 03:00, sem overlap, output em log.
Schedule::command('backup:database')->dailyAt('03:00')->withoutOverlapping(60)->runInBackground();

// Arquivamento semanal de registos diários com mais de 1 ano para a tabela de arquivo.
// Corre todos os domingos às 04:00.
Schedule::command('archive:daily-records --older-than=365')
    ->weeklyOn(0, '04:00')
    ->withoutOverlapping(60)
    ->runInBackground();

// Poda do trilho de auditoria. A retenção (730 dias) está em config/activitylog.php.
// Sem isto a tabela activity_log cresce sem limite — nada lá é apagado de outra forma.
Schedule::command('activitylog:clean')
    ->weeklyOn(1, '04:30')
    ->withoutOverlapping(30)
    ->runInBackground();

// Processamento da fila 'daily-records', 'sensor-sync' e 'default' a cada minuto.
// Não existe um serviço Railway dedicado a queue:work — isto aproveita o scheduler já ativo
// para processar as filas. Se o volume crescer, considerar um worker dedicado.
// O --max-time=50 limita cada execução a 50s, logo 5 minutos de mutex é folga suficiente.
Schedule::command('queue:work --queue=daily-records,sensor-sync,default --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5);

// ProcessDailyRecordAfterCreate::failed() já avisa os admins quando UM job
// esgota as tentativas, mas isso não cobre um cenário mais largo (ex: a
// tabela de stock ficou indisponível e vários registos falham seguidos).
// Este comando olha para failed_jobs e avisa se houver falhas novas na fila
// 'daily-records' desde a última verificação — dedup pelo maior id já visto,
// em cache "forever" (não há tabela própria para isto, e não vale a pena
// criar uma só para um contador).
Artisan::command('daily-records:verificar-falhas', function () {
    $ultimoIdVisto = (int) Cache::get('daily_records_falhas_ultimo_id', 0);

    $novasFalhas = DB::table('failed_jobs')
        ->where('queue', 'daily-records')
        ->where('id', '>', $ultimoIdVisto)
        ->get();

    if ($novasFalhas->isEmpty()) {
        return;
    }

    Cache::forever('daily_records_falhas_ultimo_id', (int) $novasFalhas->max('id'));

    $destinatarios = User::role('admin')->get();
    if ($destinatarios->isEmpty()) {
        return;
    }

    Notification::make()
        ->danger()
        ->title('Falhas na fila de registos diários')
        ->body($novasFalhas->count().' job(s) da fila "daily-records" falharam definitivamente. Stock, torneira e alertas de não-conformidade podem estar por processar nesses registos.')
        ->sendToDatabase($destinatarios);
})->purpose('Avisa os admins quando a fila daily-records acumula falhas permanentes por processar');

Schedule::command('daily-records:verificar-falhas')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

// Push dos timers de retrolavagem vencidos. Faz polling curto (~50s, ciclos de 5s)
// para latência baixa sem worker dedicado — mesmo padrão do queue:work acima.
Schedule::command('timers:fire-due')
    ->everyMinute()
    ->withoutOverlapping(10);

// Avisa admin+técnico de torneiras abertas há mais tempo que o limite configurado.
// Não precisa de precisão ao minuto — o limite é em horas.
Schedule::command('torneiras:verificar-abertas')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

// Resumo de conformidade nos horários configurados em Definições do Sistema
// (AppSetting 'digest_conformidade_horas'). Precisa de granularidade ao minuto
// para acertar o horário; o próprio comando faz o dedup por slot.
Schedule::command('notificacoes:resumo-conformidade')
    ->everyMinute()
    ->withoutOverlapping(5);

// Anúncios personalizados (Notificações Personalizadas) — únicos e diários.
// Precisa de granularidade ao minuto; o próprio comando faz o dedup.
Schedule::command('notificacoes:custom-fire-due')
    ->everyMinute()
    ->withoutOverlapping(5);

// ── Fase 1: Automação & Inteligência Operacional ────────────────────────────

// Regras de negócio automáticas: auto-incidentes (3x violação/dia/piscina),
// escalação de incidentes sem resposta >24h, fecho automático de stock.
Schedule::command('regras:executar')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

// Verificação de tendências degradantes nos parâmetros (pH, cloro).
// Cada 6h é suficiente — tendências são de longo prazo.
Schedule::command('tendencias:verificar')
    ->everySixHours()
    ->withoutOverlapping(30);

// Resumo operacional de fim de turno — horários configuráveis em Definições.
// Corre everyMinute; o comando faz dedup por slot (mesmo padrão do digest).
Schedule::command('notificacoes:resumo-turno')
    ->everyMinute()
    ->withoutOverlapping(5);

// Comparação semanal de conformidade — domingos às 09:00.
Schedule::command('notificacoes:comparacao-semanal')
    ->weeklyOn(0, '09:00')
    ->withoutOverlapping(30);

// ── Fase 3: Analytics & Business Intelligence ───────────────────────────────

// Relatório mensal automático (livro sanitário do mês anterior) — dia 1 às 06:00.
Schedule::command('relatorio:mensal-automatico')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping(60);

// Housekeeping de alert states: poda de >7 dias a cada hora.
Schedule::command('alerts:housekeeping')
    ->hourly()
    ->withoutOverlapping(30);
