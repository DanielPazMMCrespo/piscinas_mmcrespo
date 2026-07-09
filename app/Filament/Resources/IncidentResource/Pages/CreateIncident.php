<?php declare(strict_types=1);
namespace App\Filament\Resources\IncidentResource\Pages;


use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource;
use App\Models\IncidentMessage;
use App\Models\User;
use App\Notifications\IncidentCreatedNotification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Notification;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->requiresConfirmation()
                ->modalHeading('Confirmar incidente')
                ->modalDescription('Confirme que a informação do incidente está correta antes de submeter.')
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }

    protected function afterCreate(): void
    {
        $incident = $this->record;

        IncidentMessage::create([
            'incident_id' => $incident->id,
            'user_id' => $incident->user_id,
            'tipo' => IncidentMessage::TIPO_SISTEMA,
            'texto' => "Incidente reportado: {$incident->descricao}",
        ]);

        Notification::send(
            User::role([UserRole::ADMIN, UserRole::TECNICO])->get(),
            new IncidentCreatedNotification($incident)
        );
    }
}
