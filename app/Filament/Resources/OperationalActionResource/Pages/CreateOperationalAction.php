<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\OperationalActionResource;
use App\Models\Installation;
use App\Models\OperationalAction;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateOperationalAction extends CreateRecord
{
    protected static string $resource = OperationalActionResource::class;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            parent::mount();

            return;
        }

        if (! $user?->hasRole(UserRole::NADADOR_SALVADOR) || ! $user->podeVer(NSPermission::ANALISE_PARAMETROS)) {
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
        $user = auth()->user();

        // NSs (lifeguards) can only create quick analysis records.
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR) && $data['tipo'] !== OperationalAction::TIPO_ANALISE_PONTUAL) {
            throw new AuthorizationException('Nadadores-salvadores podem apenas registar análises pontuais.');
        }

        if ($data['tipo'] !== OperationalAction::TIPO_REABASTECIMENTO_BIDAO || ! ($this->data['reabastecer_todas_leiria'] ?? false)) {
            return parent::handleRecordCreation($data);
        }

        $poolIds = Installation::where('name', 'Leiria')->sole()
            ->piscinas()->where('active', true)->pluck('id');

        $registos = $poolIds->map(fn (int $poolId) => static::getModel()::create([...$data, 'pool_id' => $poolId]));

        return $registos->last();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Ação registada';
    }

    // Sinal para o app.js limpar o rascunho de localStorage — sem isto o
    // rascunho da última ação gravada ficava para trás e podia ser reenviado
    // como duplicado pelo interceptor offline (ver CLAUDE.md, "estado preso"
    // BUG-07).
    protected function afterCreate(): void
    {
        $this->dispatch('operationalActionSaved');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
