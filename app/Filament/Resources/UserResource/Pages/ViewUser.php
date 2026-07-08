<?php declare(strict_types=1);
namespace App\Filament\Resources\UserResource\Pages;


use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\Action::make('resetPassword')
                ->label('Redefinir Palavra-passe')
                ->icon('heroicon-o-key')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Enviar email de redefinição')
                ->modalDescription('Tem a certeza que deseja enviar um e-mail com instruções para redefinir a palavra-passe para este utilizador?')
                ->modalSubmitActionLabel('Sim, enviar e-mail')
                ->action(function (\App\Models\User $record): void {
                    \Illuminate\Support\Facades\Password::broker()->sendResetLink(['email' => $record->email]);
                    \Filament\Notifications\Notification::make()
                        ->title('E-mail enviado')
                        ->body('As instruções para redefinir a palavra-passe foram enviadas.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
