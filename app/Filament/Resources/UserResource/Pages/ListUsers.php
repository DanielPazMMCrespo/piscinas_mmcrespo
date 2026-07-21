<?php declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Pool;
use App\Services\InvitationService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use App\Constants\UserRole;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        $isAdmin = auth()->user()?->hasRole(UserRole::ADMIN) ?? false;

        $opcoesCargo = $isAdmin
            ? [
                UserRole::GESTOR           => 'Gestor',
                UserRole::TECNICO          => 'Técnico',
                UserRole::NADADOR_SALVADOR => 'Nadador Salvador',
            ]
            : [
                UserRole::NADADOR_SALVADOR => 'Nadador Salvador',
            ];

        return [
            Action::make('convidar')
                ->label('Convidar Utilizador')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR]) ?? false)
                ->form([
                    TextInput::make('email')
                        ->label('Email do novo utilizador')
                        ->email()
                        ->required(),
                    Select::make('role')
                        ->label('Cargo')
                        ->options($opcoesCargo)
                        ->default(UserRole::NADADOR_SALVADOR)
                        ->required()
                        ->live(),
                    CheckboxList::make('pool_ids')
                        ->label('Piscinas atribuídas')
                        ->helperText('Piscinas a que este Nadador Salvador terá acesso.')
                        ->options(fn (): array => Pool::where('active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('role') === UserRole::NADADOR_SALVADOR),
                ])
                ->action(function (array $data): void {
                    // $opcoesCargo só restringe as opções mostradas no Select — sem
                    // esta reconfirmação, um Gestor podia adulterar o pedido Livewire
                    // e convidar alguém como Admin.
                    $isAdmin = auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
                    $role = $isAdmin ? $data['role'] : UserRole::NADADOR_SALVADOR;

                    try {
                        app(InvitationService::class)->send(
                            $data['email'],
                            $role,
                            auth()->user(),
                            $data['pool_ids'] ?? [],
                        );

                        Notification::make()
                            ->title('Convite enviado')
                            ->body("Email enviado para {$data['email']}. Válido 48 horas.")
                            ->success()
                            ->send();
                    } catch (\RuntimeException $e) {
                        Notification::make()
                            ->title('Não foi possível enviar')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Actions\CreateAction::make()
                ->label('Criar manualmente'),
        ];
    }
}
