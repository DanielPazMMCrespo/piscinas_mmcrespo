<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Constants\AlertType;
use App\Constants\UserRole;
use App\Models\User;
use App\Notifications\ResumoConformidadeNotification;
use App\Services\AlertasService;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Resumo periódico de conformidade das piscinas (horários configuráveis via
 * AppSetting 'digest_conformidade_horas'). Só envia se houver alguma piscina
 * fora dos limites — silêncio nos dias em que está tudo conforme.
 *
 * Corre everyMinute (routes/console.php) para acertar o horário configurado ao
 * minuto; o dedup por Cache::add garante um único envio por slot mesmo que o
 * scheduler dispare mais do que uma vez no mesmo minuto.
 */
class SendComplianceDigestCommand extends Command
{
    protected $signature = 'notificacoes:resumo-conformidade';

    protected $description = 'Envia o resumo periódico de piscinas não conformes, se houver';

    public function handle(SettingsService $settings, AlertasService $alertas): int
    {
        $horarios = $settings->getArray('digest_conformidade_horas', ['08:00', '13:00', '18:00']);
        $agora = now()->format('H:i');

        if (! in_array($agora, $horarios, true)) {
            return self::SUCCESS;
        }

        $chave = 'digest_conformidade_enviado_'.now()->toDateString().'_'.$agora;
        if (! Cache::add($chave, true, now()->endOfDay())) {
            return self::SUCCESS;
        }

        $resultado = $alertas->calcular(null);

        $linhas = collect($resultado['alertas'])
            ->filter(fn (array $alerta, string $chave) => Str::startsWith($chave, AlertType::FORA_LIMITES.'|')
                || Str::startsWith($chave, AlertType::TEMPERATURA.'|'))
            ->map(fn (array $alerta) => $alerta['titulo'])
            ->values()
            ->all();

        if ($linhas === []) {
            return self::SUCCESS;
        }

        $destinatarios = User::role([UserRole::ADMIN, UserRole::GESTOR])->get();
        if ($destinatarios->isEmpty()) {
            return self::SUCCESS;
        }

        Notification::send($destinatarios, new ResumoConformidadeNotification($linhas, $agora));

        return self::SUCCESS;
    }
}
