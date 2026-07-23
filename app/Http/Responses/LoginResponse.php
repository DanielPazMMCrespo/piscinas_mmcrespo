<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource;
use Filament\Http\Responses\Auth\Contracts\LoginResponse as LoginResponseContract;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if (auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)) {
            return redirect()->to(DailyRecordResource::getUrl('create'));
        }

        return redirect()->intended(filament()->getUrl());
    }
}
