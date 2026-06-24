<?php declare(strict_types=1);
namespace App\Providers\Filament;


use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Enums\ThemeMode;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Rmsramos\Activitylog\ActivitylogPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->font('Inter')
            ->login(\App\Filament\Pages\Auth\Login::class)
            ->brandName('Piscinas MMCrespo')
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/logo-mmcrespo.png'))
            ->colors([
                'primary' => Color::hex('#2b9cd8'),
                'success' => Color::hex('#76b82a'),
                'warning' => Color::Amber,
                'danger' => Color::hex('#dc2626'),
                'gray' => Color::Zinc,
            ])
            ->databaseNotifications()
            // Light mode por defeito: legibilidade à beira da piscina, ao sol direto
            // (o utilizador pode na mesma alternar para escuro).
            ->defaultThemeMode(ThemeMode::Light)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(MaxWidth::ScreenTwoExtraLarge)
            ->navigationGroups([
                'Operação',
                'Inventário',
                'Estrutura',
                'Sistema',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => session('mmc_sem_cargo')
                    ? '<div class="rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 p-4 text-sm text-danger-700 dark:text-danger-400 mb-4">A sua conta não tem um cargo atribuído. Contacte o administrador.</div>'
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render("@vite('resources/js/app.js')"),
            )
            // Tags PWA (manifest, ícones, service worker) — torna a app instalável no telemóvel.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.pwa-head')->render(),
            )
            // Navegação inferior fixa no telemóvel (Bottom Nav)
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => auth()->check() ? view('filament.bottom-nav')->render() : '',
            )

            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => <<<'HTML'
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css"/>
<script src="https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js" defer></script>
HTML,
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                \App\Http\Middleware\RequirePasswordChange::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                ActivitylogPlugin::make()
                    ->navigationGroup('Sistema')
                    ->navigationSort(99)
                    ->authorize(fn () => auth()->user()?->hasRole('admin')),
            ]);
    }
}
