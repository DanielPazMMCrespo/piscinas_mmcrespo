<?php

declare(strict_types=1);

namespace App\Filament\Resources\PoolAccessRequestResource\Pages;

use App\Filament\Resources\PoolAccessRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPoolAccessRequests extends ListRecords
{
    protected static string $resource = PoolAccessRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
