<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource;
use App\Models\Installation;
use App\Models\OperationalAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateOperationalAction extends CreateRecord
{
    protected static string $resource = OperationalActionResource::class;

    public function mount(): void
    {
        if (! auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            throw new AuthorizationException('Sem acesso a ações operacionais.');
        }

        parent::mount();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        if ($data['tipo'] !== OperationalAction::TIPO_REABASTECIMENTO_BIDAO || ! ($this->data['reabastecer_todas_leiria'] ?? false)) {
            return parent::handleRecordCreation($data);
        }

        $poolIds = Installation::where('name', 'Leiria')->sole()
            ->piscinas()->where('active', true)->pluck('id');

        $registos = $poolIds->map(fn (int $poolId) => static::getModel()::create([...$data, 'pool_id' => $poolId]));

        return $registos->last();
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->requiresConfirmation()
            ->modalHeading('Confirmar ação operacional')
            ->modalDescription(fn (): string => $this->mensagemConfirmacao())
            ->modalSubmitActionLabel('Confirmar e guardar');
    }

    private function mensagemConfirmacao(): string
    {
        return match ($this->data['tipo'] ?? null) {
            OperationalAction::TIPO_REABASTECIMENTO_BIDAO => ($this->data['reabastecer_todas_leiria'] ?? false)
                ? 'Esta ação vai reabastecer o bidão de dosagem das 3 piscinas de Leiria. Confirmar?'
                : 'Esta ação vai reabastecer o bidão de dosagem da piscina. Confirmar?',
            OperationalAction::TIPO_TORNEIRA => 'Esta ação vai atualizar o alerta de torneira da piscina. Confirmar?',
            default => 'Confirmar o registo desta ação operacional?',
        };
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ação registada';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
