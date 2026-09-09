<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Enums\EstadoConformidade;
use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Services\CacheService;
use App\Services\DailyRecordService;
use Filament\Actions\Action;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class CreateDailyRecord extends CreateRecord
{
    protected static string $resource = DailyRecordResource::class;

    public bool $isCreating = false;

    /**
     * Contexto do atalho "Registo Rápido". Vive em propriedades do componente
     * porque os POSTs do Livewire não levam query string: lido de `request()` no
     * mount e reaplicado ao form builder em cada pedido seguinte.
     */
    public bool $modoRapido = false;

    public ?int $poolFixo = null;

    public function mount(): void
    {
        $this->modoRapido = request()->query('quick') == '1';
        $this->poolFixo = request()->integer('pool') ?: null;

        DailyRecordFormBuilder::aplicarContexto($this->modoRapido, $this->poolFixo);

        parent::mount();
    }

    public function hydrate(): void
    {
        DailyRecordFormBuilder::aplicarContexto($this->modoRapido, $this->poolFixo);
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var DailyRecordService $service */
        $service = app(DailyRecordService::class);
        $user = auth()->user();

        $record = $service->createRecords($user, $data);

        if ($record === null) {
            throw ValidationException::withMessages([
                'pools' => 'Nenhum registo de piscina foi criado. Verifique se tem piscinas atribuídas.',
            ]);
        }

        return $record;
    }

    protected function afterCreate(): void
    {
        app(CacheService::class)->invalidateAlerts(auth()->id());
    }

    public function create(bool $another = false): void
    {
        if ($this->isCreating) {
            return;
        }

        $this->isCreating = true;
        $this->authorizeAccess();

        // Lock adquirido à mão em vez de Cache::lock()->get($closure): a forma com
        // closure devolve false tanto quando o lock não é adquirido como quando o
        // closure devolve false, e uma falha de validação do cloro acabava a
        // mostrar "Submissão duplicada" em vez do erro real.
        $lock = Cache::lock('create_record_'.auth()->id(), 10);

        if (! $lock->get()) {
            $this->isCreating = false;

            Notification::make()
                ->danger()
                ->title('Submissão duplicada')
                ->body('O registo já está a ser processado. Por favor aguarde.')
                ->send();

            return;
        }

        try {
            $this->beginDatabaseTransaction();
            $this->callHook('beforeValidate');
            $data = $this->form->getState();
            $this->callHook('afterValidate');

            if (! $this->validatePoolsCloro($data)) {
                $this->rollBackDatabaseTransaction();
                $this->isCreating = false;

                Notification::make()
                    ->danger()
                    ->title('Corrija os campos assinalados')
                    ->body('O cloro total não pode ser inferior ao cloro livre.')
                    ->send();

                return;
            }

            $data = $this->mutateFormDataBeforeCreate($data);
            $this->callHook('beforeCreate');

            $this->record = $this->handleRecordCreation($data);

            $this->callHook('afterCreate');

            $this->commitDatabaseTransaction();
            $this->rememberData();

            $this->form->model($this->getRecord()::class);
            $this->record = null;
            $this->fillForm();
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();
            $this->isCreating = false;

            return;
        } catch (ValidationException $exception) {
            $this->rollBackDatabaseTransaction();
            $this->isCreating = false;

            throw $exception;
        } catch (\Throwable $exception) {
            $this->rollBackDatabaseTransaction();
            $this->isCreating = false;

            \Illuminate\Support\Facades\Log::error('Erro ao criar registo diário: '.$exception->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $exception->getTraceAsString(),
            ]);

            Notification::make()
                ->danger()
                ->title('Erro ao gravar o registo')
                ->body('Ocorreu um erro ao processar o registo: '.$exception->getMessage())
                ->send();

            return;
        } finally {
            $lock->release();
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

    public function validarERegistosGuardar(): void
    {
        try {
            $data = $this->form->getState();
        } catch (ValidationException $e) {
            $mensagens = collect($e->errors())->flatten()->unique()->values();

            Notification::make()
                ->danger()
                ->title('Corrija os campos assinalados')
                ->body($mensagens->implode("\n"))
                ->send();

            throw $e;
        } catch (\Throwable $e) {
            Notification::make()
                ->danger()
                ->title('Erro ao validar formulário')
                ->body('O formulário expirou ou contém dados inválidos. Por favor, recarregue a página.')
                ->send();

            return;
        }

        // Primeiro o erro que impede gravar. Antes esta verificacao so corria
        // dentro do create(), ou seja depois do slide-over de confirmacao: o
        // tecnico confirmava e so ai levava com o erro.
        if (! $this->validatePoolsCloro($data)) {
            Notification::make()
                ->danger()
                ->title('Corrija os campos assinalados')
                ->body('O cloro total não pode ser inferior ao cloro livre.')
                ->send();

            return;
        }

        // Depois a confirmacao, que existe para o tecnico reler o que vai
        // entrar no livro sanitario. Num dia em que tudo esta dentro dos
        // limites os valores ja foram validados campo a campo pelo semaforo, e
        // o passo extra nao acrescenta nada -- e cerimonia, tres vezes por dia.
        // E o mesmo critero que o Kanban ja usa, onde so o vermelho exige
        // confirmacao (QuadroOperacionalWidget::resolveViolationAction).
        if ($this->algumaLeituraForaDosLimites()) {
            $this->mountAction('confirmarCriacao');

            return;
        }

        $this->create();
    }

    /**
     * Alguma piscina do formulario tem uma leitura fora dos limites CN 14/DA?
     *
     * Usa a fonte unica (DailyRecord::avaliarConformidade), com o pH da mesma
     * leitura -- a banda legal do cloro livre depende dele -- e a data de hoje,
     * para o registo ser avaliado pelo regime em vigor.
     */
    public function algumaLeituraForaDosLimites(): bool
    {
        foreach (($this->data['pools'] ?? []) as $poolId => $dadosPiscina) {
            $piscina = Pool::find($poolId);

            if ($piscina === null) {
                continue;
            }

            $ph = $dadosPiscina['ns_ph'] ?? null;
            $ph = filled($ph) && is_numeric(str_replace(',', '.', (string) $ph))
                ? (float) str_replace(',', '.', (string) $ph)
                : null;

            foreach (DailyRecordFormBuilder::CAMPOS_LEITURA_LEGAL as $campo) {
                $valor = $dadosPiscina[$campo] ?? null;

                if (blank($valor)) {
                    continue;
                }

                $estado = DailyRecord::avaliarConformidade($campo, $valor, $piscina, ph: $ph, data: now())['estado'];

                if ($estado === EstadoConformidade::VERMELHO) {
                    return true;
                }
            }
        }

        return false;
    }

    private function validatePoolsCloro(array $data): bool
    {
        $pools = $data['pools'] ?? [];
        $hasErrors = false;

        foreach ($pools as $poolId => $poolData) {
            $cloroTotal = $poolData['ns_cloro_total'] ?? null;
            $cloroLivre = $poolData['ns_cloro_livre'] ?? null;

            if (filled($cloroTotal) && filled($cloroLivre) && (float) $cloroTotal < (float) $cloroLivre) {
                $this->addError("data.pools.{$poolId}.ns_cloro_total", 'O cloro total não pode ser inferior ao cloro livre.');
                $hasErrors = true;
            }

            $cloroTotal = $poolData['cloro_total'] ?? null;
            $cloroLivre = $poolData['cloro_livre'] ?? null;

            if (filled($cloroTotal) && filled($cloroLivre) && (float) $cloroTotal < (float) $cloroLivre) {
                $this->addError("data.pools.{$poolId}.cloro_total", 'O cloro total não pode ser inferior ao cloro livre.');
                $hasErrors = true;
            }
        }

        return ! $hasErrors;
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('Gravar Registos')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->size('lg')
                ->action('validarERegistosGuardar')
                ->keyBindings(['mod+s'])
                // Sem piscinas no formulário não há nada para gravar — o botão
                // só levava ao erro de "nenhum registo criado".
                ->hidden(fn (): bool => blank($this->data['pools'] ?? [])),
            Action::make('confirmarCriacao')
                ->label('Confirmar e guardar')
                ->extraAttributes(['class' => 'hidden'])
                ->action(fn () => $this->create())
                ->requiresConfirmation()
                ->slideOver()
                ->modalHeading('Resumo e Confirmação de Registos')
                ->modalContent(function () {
                    $data = $this->data;
                    $poolsData = $data['pools'] ?? [];
                    $valores = [];

                    foreach ($poolsData as $poolId => $poolData) {
                        $pool = Pool::find($poolId);
                        if (! $pool) {
                            continue;
                        }

                        $valores[] = [
                            'piscina' => $pool->name,
                            'ph' => $poolData['ns_ph'] ?? null,
                            'cloro_livre' => $poolData['ns_cloro_livre'] ?? null,
                            'cloro_total' => $poolData['ns_cloro_total'] ?? null,
                            'temperatura' => $poolData['ns_temperatura'] ?? null,
                        ];
                    }

                    return view('filament.daily-record-modal-summary', ['valores' => $valores]);
                })
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }
}
