<?php declare(strict_types=1);
namespace App\Filament\Widgets;


use App\Constants\UserRole;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\User;
use App\Notifications\IncidentMessageNotification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Timeline de mensagens de um incidente (chat entre quem reporta e quem
 * corrige) + caixa de resposta. Mistura mensagens livres com mensagens
 * automáticas de mudança de estado geradas noutros pontos (CreateIncident,
 * ação "Resolver"). Responder a um incidente resolvido reabre-o.
 */
class IncidentChatWidget extends Widget
{
    protected static string $view = 'filament.widgets.incident-chat';

    protected int|string|array $columnSpan = 'full';

    public ?Incident $record = null;

    public string $texto = '';

    public function enviarMensagem(): void
    {
        $texto = trim($this->texto);

        if ($texto === '' || $this->record === null) {
            return;
        }

        $incident = $this->record;
        $autor = auth()->user();

        DB::transaction(function () use ($incident, $autor, $texto): void {
            IncidentMessage::create([
                'incident_id' => $incident->id,
                'user_id' => $autor->id,
                'tipo' => IncidentMessage::TIPO_MENSAGEM,
                'texto' => $texto,
            ]);

            if ($incident->status === 'resolvido') {
                $incident->update([
                    'status' => 'aberto',
                    'resolvido_em' => null,
                    'resolvido_por' => null,
                    'resolucao' => null,
                ]);

                IncidentMessage::create([
                    'incident_id' => $incident->id,
                    'user_id' => $autor->id,
                    'tipo' => IncidentMessage::TIPO_SISTEMA,
                    'texto' => 'Reaberto automaticamente após nova mensagem.',
                ]);
            }
        });

        if ($autor->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            $incident->utilizador?->notify(new IncidentMessageNotification($incident, $autor, $texto));
        } else {
            Notification::send(
                User::role([UserRole::ADMIN, UserRole::TECNICO])->get(),
                new IncidentMessageNotification($incident, $autor, $texto)
            );
        }

        $this->texto = '';
        $this->record->refresh();
    }
}
