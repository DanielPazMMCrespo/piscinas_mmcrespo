<?php

declare(strict_types=1);

namespace App\Filament\Resources\OperationalActionResource\Pages;

use App\Filament\Resources\OperationalActionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOperationalAction extends EditRecord
{
    protected static string $resource = OperationalActionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
