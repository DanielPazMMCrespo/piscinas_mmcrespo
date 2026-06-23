<?php declare(strict_types=1);

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Services\InvitationService;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use App\Constants\UserRole;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('convidar')
                ->label('Convidar Utilizador')
                ->icon('heroicon-o-envelope')
                ->color('primary')
                ->form([
                    TextInput::make('email')
                        ->label('Email do novo utilizador')
                        ->email()
                        ->required(),
                    Select::make('role')
                        ->label('Cargo')
                        ->options([
                            UserRole::GESTOR           => 'Gestor',
                            UserRole::TECNICO          => 'Técnico',
                            UserRole::NADADOR_SALVADOR => 'Nadador Salvador',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    try {
                        app(InvitationService::class)->send(
                            $data['email'],
                            $data['role'],
                            auth()->user(),
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
