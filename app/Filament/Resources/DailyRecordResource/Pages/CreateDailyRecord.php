<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource\Pages;


use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use App\Models\DailyRecord;
use App\Models\Pool;
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

    /** IDs das piscinas ainda por gravar nesta visita, pela ordem da fila (exclui a atual). */
    public array $filaRestante = [];

    /** Total de piscinas desta visita (0 = fluxo normal de piscina única). Fixado no 1º "Guardar e seguir". */
    public int $filaTotal = 0;

    public function getSubheading(): ?string
    {
        if ($this->filaTotal <= 1) {
            return null;
        }

        $atual = $this->filaTotal - count($this->filaRestante);
        $piscinaId = $this->data['pool_id'] ?? null;
        $piscina = $piscinaId ? Pool::find($piscinaId) : null;

        return "Piscina {$atual} de {$this->filaTotal}" . ($piscina ? " — {$piscina->name}" : '');
    }

    private function persistirRegisto(): ?DailyRecord
    {
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
                $this->form->model($this->getRecord())->saveRelationships();
                $this->callHook('afterCreate');

                $this->commitDatabaseTransaction();
                $this->rememberData();

                return true;
            });

            if (! $success) {
                $this->isCreating = false;
                Notification::make()
                    ->danger()
                    ->title('Submissão duplicada')
                    ->body('O registo já está a ser processado. Por favor aguarde.')
                    ->send();
                return null;
            }
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();
            $this->isCreating = false;
            return null;
        } catch (\Throwable $exception) {
            $this->rollBackDatabaseTransaction();
            $this->isCreating = false;
            throw $exception;
        }

        $this->isCreating = false;

        return $this->record;
    }

    private function resetarFormularioParaNovoRegisto(): void
    {
        $this->form->model(DailyRecord::class);
        $this->record = null;
        $this->fillForm();
    }

    public function create(bool $another = false): void
    {
        if ($this->isCreating) {
            return;
        }

        $registo = $this->persistirRegisto();

        if ($registo === null) {
            return;
        }

        $this->resetarFormularioParaNovoRegisto();
        $this->filaRestante = [];
        $this->filaTotal = 0;

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
        $notificationData = $notificacao->toArray();
        $this->dispatch('dailyRecordSaved', notification: [
            'title' => $notificationData['title'] ?? null,
            'body' => $notificationData['body'] ?? null,
            'status' => $notificationData['status'] ?? null,
        ]);
    }

    /**
     * Label do botão de submissão do wizard, refletindo se ainda há piscinas
     * por gravar na fila da visita (usado pelo submitAction do Wizard, que só
     * é renderizado no último passo — ver DailyRecordFormBuilder::form()).
     */
    public function getSubmitLabel(): string
    {
        $proximo = $this->proximaPiscinaDaFila();

        return $proximo ? "Guardar e seguir para {$proximo->name}" : 'Criar';
    }

    /**
     * Chamado pelo botão de submissão do Wizard (só visível no último passo).
     * Monta a mesma action que getFormActions() usaria — "create" ou
     * "guardarEAvancar" — para reaproveitar a confirmação e o resumo.
     */
    public function submeterFormulario(): void
    {
        $this->mountAction($this->proximaPiscinaDaFila() ? 'guardarEAvancar' : 'create');
    }

    private function proximaPiscinaDaFila(): ?Pool
    {
        if (! empty($this->filaRestante)) {
            return Pool::find($this->filaRestante[0]);
        }

        $selecionadas = $this->data['outras_piscinas_visita'] ?? [];
        if (! empty($selecionadas)) {
            return Pool::find(array_values($selecionadas)[0]);
        }

        return null;
    }

    public function guardarEAvancar(): void
    {
        if ($this->isCreating) {
            return;
        }

        if (empty($this->filaRestante) && $this->filaTotal === 0) {
            $selecionadas = array_values(array_map('intval', $this->data['outras_piscinas_visita'] ?? []));
            $this->filaRestante = $selecionadas;
            $this->filaTotal = count($selecionadas) + 1;
        }

        $piscinaAtual = Pool::find($this->data['pool_id'] ?? null);
        $responsavelId = $this->data['user_id'] ?? auth()->id();
        $dataHora = $this->data['registado_em'] ?? now();

        $registo = $this->persistirRegisto();

        if ($registo === null) {
            return;
        }

        Notification::make()
            ->success()
            ->title($piscinaAtual ? "{$piscinaAtual->name} guardada" : 'Registo guardado')
            ->body('A avançar para a próxima piscina da visita.')
            ->send();

        $proximoPoolId = array_shift($this->filaRestante);

        $this->resetarFormularioParaNovoRegisto();

        $ultimo = DailyRecordFormBuilder::ultimoRegisto($proximoPoolId);
        $this->data['pool_id'] = $proximoPoolId;
        $this->data['user_id'] = $responsavelId;
        $this->data['registado_em'] = $dataHora;
        $this->data['bomba_ferrada'] = $ultimo?->bomba_ferrada;
        $this->data['agua_modo'] = $ultimo?->agua_modo;
        $this->data['tanque_ok'] = $ultimo?->tanque_ok;
    }

    private function conteudoModalConfirmacao()
    {
        $data = $this->data;
        $pool = Pool::find($data['pool_id'] ?? null);
        $problemas = [];
        foreach (['ph', 'cloro_livre', 'temperatura', 'transparencia'] as $campo) {
            if (isset($data[$campo]) && $data[$campo] !== '') {
                $estado = DailyRecord::avaliarConformidade($campo, $data[$campo], $pool);
                if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                    $problemas[] = $estado['mensagem'];
                }
            }
        }
        if (isset($data['cloro_livre'], $data['cloro_total']) && $data['cloro_livre'] !== '' && $data['cloro_total'] !== '') {
            $combinado = (float)$data['cloro_total'] - (float)$data['cloro_livre'];
            $estado = DailyRecord::avaliarConformidade('cloro_combinado', $combinado, $pool);
            if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                $problemas[] = $estado['mensagem'];
            }
        }
        return view('filament.daily-record-modal-summary', ['problemas' => $problemas]);
    }

    protected function getFormActions(): array
    {
        $proximo = $this->proximaPiscinaDaFila();

        $acaoPrincipal = $proximo
            ? Action::make('guardarEAvancar')
                ->label("Guardar e seguir para {$proximo->name}")
                ->action(fn () => $this->guardarEAvancar())
                ->requiresConfirmation()
                ->modalHeading('Confirmar registo')
                ->modalContent(fn () => $this->conteudoModalConfirmacao())
                ->modalSubmitActionLabel('Confirmar e guardar')
                ->keyBindings(['mod+s'])
            : Action::make('create')
                ->label('Criar')
                ->action(fn () => $this->create())
                ->requiresConfirmation()
                ->modalHeading('Confirmar registo')
                ->modalContent(fn () => $this->conteudoModalConfirmacao())
                ->modalSubmitActionLabel('Confirmar e guardar')
                ->keyBindings(['mod+s']);

        return [
            $acaoPrincipal,
            $this->getCancelFormAction(),
        ];
    }

    /**
     * As actions de getFormActions() continuam registadas (necessário para
     * mountAction() em submeterFormulario()) — isto só esconde a barra
     * default da page, que mostrava "Criar/Cancelar" em todos os passos do
     * Wizard. O botão real vive no submitAction do Wizard (último passo).
     */
    public function getCachedFormActions(): array
    {
        return [];
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
