<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstallationResource\Pages;

use App\Filament\Resources\InstallationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInstallation extends CreateRecord
{
    protected static string $resource = InstallationResource::class;
}
