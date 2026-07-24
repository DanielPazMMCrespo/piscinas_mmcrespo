<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\AvatarProviders\GenericAvatarProvider;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\RequirePasswordChange;
use Filament\Enums\ThemeMode;
use Filament\Forms\Components\TextInput;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
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
    public function boot(): void
    {
        // ->numeric() renderiza <input type="number"> por omissão, e o browser
        // descarta silenciosamente a vírgula digitada (7,2 -> "72"), corrompendo
        // valores sem qualquer erro visível. Forçar type="text" mantém o teclado
        // decimal (inputmode="decimal" já definido por numeric()) mas deixa a
        // conversão vírgula->ponto do app.js atuar antes da validação.
        TextInput::configureUsing(function (TextInput $component): void {
            $component->type(fn (): ?string => $component->isNumeric() ? 'text' : null);
        });
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->font('Lato')
            ->login(Login::class)
            ->brandName('Piscinas MMCrespo')
            ->brandLogo(fn () => view('filament.brand-logo'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/logo-mmcrespo.png'))
            ->colors([
                'primary' => Color::hex('#0284c7'), /* Aqua / Sky Cyan 600 */
                'success' => Color::hex('#059669'), /* Emerald 600 */
                'warning' => Color::Amber,
                'danger' => Color::hex('#f43f5e'), /* Rose 500 */
                'gray' => Color::Zinc,
            ])
            ->databaseNotifications()
            ->defaultAvatarProvider(GenericAvatarProvider::class)
            // Light mode por defeito: legibilidade à beira da piscina, ao sol direto
            // (o utilizador pode na mesma alternar para escuro).
            ->defaultThemeMode(ThemeMode::Light)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth(MaxWidth::ScreenTwoExtraLarge)
            ->navigationGroups([
                'Registo Diário',
                'Operação',
                'Dados',
                'Stock',
                'Estrutura',
                'Sistema',
                NavigationGroup::make('Logs')->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => match (true) {
                    (bool) session('mmc_inativo') => '<div class="rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 p-4 text-sm text-danger-700 dark:text-danger-400 mb-4"><strong>Ficou sem acesso.</strong><br>A sua conta foi encerrada por inatividade. Se acha que isto é um engano, contacte o administrador.</div>',
                    (bool) session('mmc_sem_cargo') => '<div class="rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 p-4 text-sm text-danger-700 dark:text-danger-400 mb-4">A sua conta não tem um cargo atribuído. Contacte o administrador.</div>',
                    default => '',
                },
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => Blade::render("@vite(['resources/css/app.css', 'resources/js/app.js'])"),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, user-scalable=yes">'.
                    '<meta name="csrf-token" content="'.csrf_token().'">'.
                    '<script>window.__userId = '.(auth()->id() ?? 'null').';'.
                    'window.__vapidPublicKey = '.json_encode(config('webpush.vapid.public_key')).';'.
                    'window.__poolNomes = '.json_encode(\App\Models\Pool::pluck('name', 'id')).';</script>',
            )
            // Barra global fixa com os timers de retrolavagem/enxaguamento ativos
            // (visível em qualquer página/passo do wizard, não só no fieldset de origem)
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => (auth()->check() ? view('filament.timer-bar')->render() : '') . view('filament.preloader')->render()
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
            // Alerta/Prompt para ativar notificações
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => auth()->check() ? view('filament.notification-prompt')->render() : '',
            )


            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => <<<'HTML'
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
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
                RequirePasswordChange::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                ActivitylogPlugin::make()
                    ->navigationGroup('Logs')
                    ->navigationSort(99)
                    ->authorize(fn () => auth()->user()?->hasRole('admin')),
            ]);
    }
}
