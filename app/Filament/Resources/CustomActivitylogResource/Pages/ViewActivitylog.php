<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomActivitylogResource\Pages;

use App\Filament\Resources\CustomActivitylogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewActivitylog extends ViewRecord
{
    protected static string $resource = CustomActivitylogResource::class;
}
