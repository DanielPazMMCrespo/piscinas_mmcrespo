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
                ->label('Enviar Email de Redefinição')
                ->icon('heroicon-o-envelope')
                ->color('info')
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
            Actions\Action::make('forceChangePassword')
                ->label('Forçar Palavra-passe')
                ->icon('heroicon-o-lock-closed')
                ->color('danger')
                ->form([
                    \Filament\Forms\Components\TextInput::make('password')
                        ->label('Nova Palavra-passe')
                        ->password()
                        ->minLength(8)
                        ->requiredWithout('pin'),
                    \Filament\Forms\Components\TextInput::make('pin')
                        ->label('Novo PIN')
                        ->password()
                        ->minLength(4)
                        ->requiredWithout('password'),
                    \Filament\Forms\Components\Checkbox::make('consciencia')
                        ->label('Tenho consciência que vou alterar as credenciais deste utilizador')
                        ->required()
                ])
                ->action(function (\App\Models\User $record, array $data): void {
                    if (!empty($data['password'])) {
                        $record->password = \Illuminate\Support\Facades\Hash::make($data['password']);
                    }
                    if (!empty($data['pin'])) {
                        $record->pin = \Illuminate\Support\Facades\Hash::make($data['pin']);
                    }
                    $record->save();
                    \Filament\Notifications\Notification::make()
                        ->title('Credenciais alteradas com sucesso')
                        ->success()
                        ->send();
                })
                ->modalHeading('Forçar Alteração Manual')
                ->modalDescription('Atenção: está prestes a definir manualmente a palavra-passe/PIN de um utilizador. Aguarde 5 segundos para confirmar.')
                ->modalSubmitActionLabel('Confirmar Alteração')
                ->modalSubmitAction(fn (\Filament\Actions\StaticAction $action) => $action->extraAttributes([
                    'x-data' => '{ seconds: 5, init() { const i = setInterval(() => { if (this.seconds > 0) { this.seconds-- } else { clearInterval(i) } }, 1000) } }',
                    'x-bind:disabled' => 'seconds > 0',
                    'style' => 'transition: all 0.3s;',
                ])),
        ];
    }
}
