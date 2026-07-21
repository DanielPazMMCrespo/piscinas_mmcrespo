<?php declare(strict_types=1);
namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;

class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informação Pessoal')
                    ->schema([
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ]),
                
                Section::make('Preferências de Notificação')
                    ->description('Escolha como deseja ser notificado sobre diferentes eventos da aplicação.')
                    ->schema([
                        $this->getNotificationPreferencesSchema('Incidentes', 'incidentes', 'Alertas de novos incidentes e novas mensagens na conversa.'),
                        $this->getNotificationPreferencesSchema('Operação', 'operacao', 'Alertas de fim de timer de retrolavagem, torneiras abertas, ou bidões com nível baixo.'),
                        $this->getNotificationPreferencesSchema('Conformidade', 'conformidade', 'Resumos diários de conformidade e alertas de parâmetros fora dos limites.'),
                        $this->getNotificationPreferencesSchema('Sistema', 'sistema', 'Avisos gerais e anúncios dos administradores.'),
                    ]),
            ]);
    }

    private function getNotificationPreferencesSchema(string $label, string $key, string $description): Section
    {
        return Section::make($label)
            ->description($description)
            ->schema([
                Toggle::make("notification_preferences.{$key}.push")
                    ->label('Notificação Push (Dispositivo)')
                    ->default(true),
                Toggle::make("notification_preferences.{$key}.mail")
                    ->label('Notificação por E-mail')
                    ->default($key === 'conformidade'),
            ])
            ->columns(2)
            ->collapsible();
    }
}
