<?php

declare(strict_types=1);

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Models\Contracts\HasAvatar;

class GenericAvatarProvider implements AvatarProvider
{
    public function get(HasAvatar $record): string
    {
        return asset('images/user-placeholder.svg');
    }
}
