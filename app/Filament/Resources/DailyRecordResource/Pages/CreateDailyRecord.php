<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource\Pages;


use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use Filament\Actions\Action;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateDailyRecord extends CreateRecord
{
    protected static string $resource = DailyRecordResource::class;

    /** Guarda flag para evitar duplo-submit (previne re-entrada em create()). */
    public bool $isCreating = false;

    public function create(bool $another = false): void
    {
        if ($this->isCreating) {
            return;
        }

        $this->isCreating = true;
        $this->authorizeAccess();

        try {
            $this->beginDatabaseTransaction();
            $this->callHook('beforeValidate');
            $data = $this->form->getState();
            $this->callHook('afterValidate');
            $data = $this->mutateFormDataBeforeCreate($data);
            $this->callHook('beforeCreate');
            $this->record = $this->handleRecordCreation($data);
            $this->form->model($this->getRecord())->saveRelationships();
            $this->callHook('afterCreate');
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();
            $this->isCreating = false;

            return;
        } catch (\Throwable $exception) {
            $this->rollBackDatabaseTransaction();
            $this->isCreating = false;
            throw $exception;
        }

        $this->commitDatabaseTransaction();
        $this->rememberData();

        // Reset form for next record
        $this->form->model($this->getRecord()::class);
        $this->record = null;
        $this->fillForm();
        $this->isCreating = false;

        // Show persistent choice notification (dispatch real-time, bypass session)
        $notificacao = Notification::make()
            ->success()
            ->title('Registo guardado!')
            ->body('O que pretende fazer a seguir?')
            ->persistent()
            ->actions([
                NotificationAction::make('novoRegisto')
                    ->label('Novo Registo')
                    ->button()
                    ->close(),
                NotificationAction::make('dashboard')
                    ->label('Ir para o Dashboard')
                    ->button()
                    ->color('gray')
                    ->url('/admin'),
            ]);

        $notificacao->send();
        $this->dispatch('notificationSent', notification: $notificacao->toArray());
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('Criar')
                ->action(fn () => $this->create())
                ->requiresConfirmation()
                ->modalHeading('Confirmar registo')
                ->modalDescription('Confirme que os valores introduzidos estão corretos. Depois de submetido, só o administrador pode alterar este registo.')
                ->modalSubmitActionLabel('Confirmar e guardar')
                ->keyBindings(['mod+s']),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Depois de gravar o registo: (1) desconta do stock da instalação os químicos
     * adicionados; (2) avisa os administradores se houver parâmetros fora dos limites.
     * Delegado ao Job ProcessDailyRecordAfterCreate para não bloquear o request HTTP.
     */
    protected function afterCreate(): void
    {
        /** @var DailyRecord $registo */
        $registo = $this->record;

        // Delegar ao Job async — tem retry (3x), backoff, e não bloqueia o request.
        \App\Jobs\ProcessDailyRecordAfterCreate::dispatch(
            $registo->id,
            (int) auth()->id()
        );

        // Invalida cache de alertas para que o dashboard reflicta o novo registo.
        app(\App\Services\CacheService::class)->invalidateAlerts(auth()->id());
        // O AlertasService memoiza por-pedido, logo a próxima request HTTP já
        // recalcula de fresco — não é preciso limpar nada aqui.
    }

}
