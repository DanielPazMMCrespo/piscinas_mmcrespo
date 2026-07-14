<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Constants\UserRole;
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

    public bool $isCreating = false;
    
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $commonData = [
            'user_id' => $data['user_id'] ?? auth()->id(),
            'registado_em' => $data['registado_em'] ?? now(),
        ];
        
        if (isset($data['ns_foto'])) {
            $commonData['ns_foto'] = is_array($data['ns_foto']) ? array_values($data['ns_foto'])[0] : $data['ns_foto'];
        }
        
        $poolsData = $data['pools'] ?? [];
        $lastRecord = null;

        $user = auth()->user();
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            $poolIdsPermitidos = $user->piscinas()->pluck('pools.id')->all();
            foreach (array_keys($poolsData) as $poolId) {
                abort_unless(in_array((int) $poolId, $poolIdsPermitidos, true), 403);
            }
        }

        foreach ($poolsData as $poolId => $poolData) {
            $adicoes = $poolData['adicoes'] ?? [];
            unset($poolData['adicoes']); // Remove from attributes
            
            $photoFields = ['bomba_foto', 'contador_foto', 'tanque_foto', 'filtro_foto_retrolavagem', 'filtro_foto_enxaguamento', 'filtro_foto_posicao_normal'];
            foreach ($photoFields as $pf) {
                if (isset($poolData[$pf])) {
                    if (is_array($poolData[$pf])) {
                        $poolData[$pf] = !empty($poolData[$pf]) ? array_values($poolData[$pf])[0] : null;
                    } elseif ($poolData[$pf] === '') {
                        $poolData[$pf] = null;
                    }
                } else {
                    $poolData[$pf] = null;
                }
            }

            $recordData = array_merge($commonData, $poolData, ['pool_id' => $poolId]);
            $lastRecord = static::getModel()::create($recordData);
            
            // Gravar adicões no pivot (relacionamento 'adicoes')
            if (!empty($adicoes)) {
                $lastRecord->adicoes()->createMany($adicoes);
            }
            
            \App\Jobs\ProcessDailyRecordAfterCreate::dispatch($lastRecord->id, (int) auth()->id());
        }
        
        return $lastRecord;
    }
    
    protected function afterCreate(): void
    {
        app(\App\Services\CacheService::class)->invalidateAlerts(auth()->id());
    }

    public function create(bool $another = false): void
    {
        if ($this->isCreating) {
            return;
        }

        $this->isCreating = true;
        $this->authorizeAccess();

        $lockKey = 'create_record_' . auth()->id();
        try {
            $success = \Illuminate\Support\Facades\Cache::lock($lockKey, 10)->get(function () {
                $this->beginDatabaseTransaction();
                $this->callHook('beforeValidate');
                $data = $this->form->getState();
                $this->callHook('afterValidate');
                $data = $this->mutateFormDataBeforeCreate($data);
                $this->callHook('beforeCreate');
                
                $this->record = $this->handleRecordCreation($data);
                
                $this->callHook('afterCreate');

                $this->commitDatabaseTransaction();
                $this->rememberData();

                $this->form->model($this->getRecord()::class);
                $this->record = null;
                $this->fillForm();

                return true;
            });

            if (! $success) {
                $this->isCreating = false;
                Notification::make()
                    ->danger()
                    ->title('Submissão duplicada')
                    ->body('O registo já está a ser processado. Por favor aguarde.')
                    ->send();
                return;
            }
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

        $this->isCreating = false;

        $notificacao = Notification::make()
            ->success()
            ->title('Registo guardado!')
            ->body('Os registos das piscinas foram guardados. O que pretende fazer a seguir?')
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
        $notificationData = $notificacao->toArray();
        $this->dispatch('dailyRecordSaved', notification: [
            'title' => $notificationData['title'] ?? null,
            'body' => $notificationData['body'] ?? null,
            'status' => $notificationData['status'] ?? null,
        ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('Criar')
                ->action(fn () => $this->create())
                ->requiresConfirmation()
                ->modalHeading('Confirmar registos')
                ->modalContent(function () {
                    $data = $this->data;
                    $poolsData = $data['pools'] ?? [];
                    $problemasGlobais = [];
                    
                    foreach ($poolsData as $poolId => $poolData) {
                        $pool = \App\Models\Pool::find($poolId);
                        if (!$pool) continue;
                        
                        foreach (['ns_ph', 'ns_cloro_livre', 'ns_temperatura'] as $campo) {
                            if (isset($poolData[$campo]) && $poolData[$campo] !== '') {
                                $estado = \App\Models\DailyRecord::avaliarConformidade($campo, $poolData[$campo], $pool);
                                if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                                    $problemasGlobais[] = "{$pool->name} - {$estado['mensagem']}";
                                }
                            }
                        }
                        
                        if (isset($poolData['ns_cloro_livre'], $poolData['ns_cloro_total']) && $poolData['ns_cloro_livre'] !== '' && $poolData['ns_cloro_total'] !== '') {
                            $combinado = (float)$poolData['ns_cloro_total'] - (float)$poolData['ns_cloro_livre'];
                            $estado = \App\Models\DailyRecord::avaliarConformidade('cloro_combinado', $combinado, $pool);
                            if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                                $problemasGlobais[] = "{$pool->name} - {$estado['mensagem']}";
                            }
                        }
                    }
                    
                    return view('filament.daily-record-modal-summary', ['problemas' => $problemasGlobais]);
                })
                ->modalSubmitActionLabel('Confirmar e guardar')
                ->keyBindings(['mod+s']),
            $this->getCancelFormAction(),
        ];
    }
}
