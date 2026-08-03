<?php

declare(strict_types=1);

namespace App\Filament\Resources\PoolClosureResource\Pages;

use App\Filament\Resources\PoolClosureResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPoolClosure extends EditRecord
{
    protected static string $resource = PoolClosureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
