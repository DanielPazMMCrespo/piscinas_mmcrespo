<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\SentryServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    SentryServiceProvider::class,
];
