<?php declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Pages\Auth\EditProfile as BaseEditProfile;
use Illuminate\Database\Eloquent\Model;

class EditProfile extends BaseEditProfile
{
    public function form(Form $form): Form
    {
        return $form
            ->schema([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                Toggle::make('haptic_enabled')
                    ->label('Vibração (Feedback Haptic)')
                    ->helperText('Ativar vibrações táteis no telemóvel ao interagir com a aplicação.')
                    ->default(true),
            ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record = parent::handleRecordUpdate($record, $data);

        // Update localStorage and Alpine state immediately on the client side
        $enabledStr = $record->haptic_enabled ? 'true' : 'false';
        $disabledStr = $record->haptic_enabled ? 'false' : 'true';
        
        $this->js("
            localStorage.setItem('mmcrespo_haptic_disabled', '{$disabledStr}');
            window.dispatchEvent(new CustomEvent('haptic-preference-updated', { detail: { enabled: {$enabledStr} } }));
        ");

        return $record;
    }
}
