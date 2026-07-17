<?php declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CustomBroadcast;
use App\Models\User;
use App\Notifications\CustomBroadcastNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Dispara os anúncios personalizados (App\Models\CustomBroadcast) criados
 * pelo admin em "Notificações Personalizadas": envio único numa data/hora
 * exata, ou diário recorrente a uma hora configurada.
 */
class FireDueCustomBroadcastsCommand extends Command
{
    protected $signature = 'notificacoes:custom-fire-due';

    protected $description = 'Dispara os anúncios personalizados vencidos (únicos e diários)';

    public function handle(): int
    {
        $this->dispararUnicos();
        $this->dispararDiarios();

        return self::SUCCESS;
    }

    private function dispararUnicos(): void
    {
        CustomBroadcast::query()
            ->where('tipo_agendamento', CustomBroadcast::TIPO_UNICO)
            ->whereNull('enviado_em')
            ->whereNotNull('enviar_em')
            ->where('enviar_em', '<=', Carbon::now())
            ->get()
            ->each(function (CustomBroadcast $broadcast): void {
                $this->enviar($broadcast, "custom-{$broadcast->id}");
                $broadcast->update(['enviado_em' => Carbon::now()]);
            });
    }

    private function dispararDiarios(): void
    {
        $agora = Carbon::now();
        $hoje = $agora->toDateString();

        CustomBroadcast::query()
            ->where('tipo_agendamento', CustomBroadcast::TIPO_DIARIO)
            ->where('ativo', true)
            ->whereNotNull('hora_diaria')
            ->get()
            ->filter(function (CustomBroadcast $broadcast) use ($agora, $hoje): bool {
                if ($broadcast->ultima_data_enviada?->toDateString() === $hoje) {
                    return false;
                }

                return Carbon::parse($broadcast->hora_diaria)->format('H:i') === $agora->format('H:i');
            })
            ->each(function (CustomBroadcast $broadcast) use ($hoje): void {
                $this->enviar($broadcast, "custom-{$broadcast->id}-{$hoje}");
                $broadcast->update(['ultima_data_enviada' => $hoje]);
            });
    }

    private function enviar(CustomBroadcast $broadcast, string $tag): void
    {
        $destinatarios = User::role($broadcast->cargos)->get();
        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::send(
            $destinatarios,
            new CustomBroadcastNotification($broadcast->titulo, $broadcast->corpo, $tag)
        );
    }
}
