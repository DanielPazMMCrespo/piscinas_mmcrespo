<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource\Pages;


use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\RecordPhoto;
use App\Models\TapAlert;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\DB;

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

    /**
     * Correlação água ↔ torneira: "ON — com água" (agua_modo='on_com_agua') é a
     * torneira forçada aberta. Abre um alerta por piscina enquanto durar; qualquer
     * outro estado num registo seguinte fecha o alerta aberto (resolução
     * automática que faz o cartão sair do Kanban).
     */
    private function gerirTorneira(DailyRecord $registo): void
    {
        if (! $registo->pool_id) {
            return;
        }

        $aberto = TapAlert::query()
            ->where('pool_id', $registo->pool_id)
            ->whereNull('resolved_at')
            ->latest('opened_at')
            ->first();

        if ($registo->agua_modo === 'on_com_agua') {
            // Já há um alerta aberto para esta piscina — não duplica.
            if (! $aberto) {
                TapAlert::create([
                    'pool_id' => $registo->pool_id,
                    'opened_record_id' => $registo->id,
                    'opened_by' => $registo->user_id,
                    'opened_at' => $registo->registado_em,
                ]);
            }

            return;
        }

        // Estado de água em branco = desconhecido. Não mexe num alerta aberto
        // (não o pode dar como resolvido — a torneira pode continuar aberta).
        if ($registo->agua_modo === null || $registo->agua_modo === '') {
            return;
        }

        // Estado explícito diferente de "ON — com água": fecha o alerta aberto.
        if ($aberto) {
            $aberto->update([
                'resolved_at' => $registo->registado_em,
                'resolved_by' => $registo->user_id,
                'resolved_record_id' => $registo->id,
                'resolution' => 'registo_seguinte',
            ]);
        }
    }

    /**
     * Cria registos em `record_photos` para cada foto no array `analises_fotos`.
     * O Filament FileUpload já armazenou os ficheiros em disk storage.
     */
    private function guardarFotos(DailyRecord $registo): void
    {
        if (empty($registo->analises_fotos) || ! is_array($registo->analises_fotos)) {
            return;
        }

        foreach ($registo->analises_fotos as $caminho) {
            RecordPhoto::create([
                'daily_record_id' => $registo->id,
                'type' => 'tecnico',
                'path' => (string) $caminho,
            ]);
        }
    }

    /**
     * Desconta as adições de químicos do stock da instalação (transação + lock).
     * Decisão: o registo sanitário é a fonte de verdade legal e nunca é bloqueado;
     * se o stock for insuficiente, desconta até zero e avisa (não impede o registo).
     */
    private function descontarStock(DailyRecord $registo): void
    {
        $instalacaoId = $registo->piscina?->instalacao?->id;
        if (! $instalacaoId || $registo->adicoes->isEmpty()) {
            return;
        }

        $insuficientes = [];

        DB::transaction(function () use ($registo, $instalacaoId, &$insuficientes): void {
            foreach ($registo->adicoes as $adicao) {
                if (! $adicao->product_id || (float) $adicao->quantity <= 0) {
                    continue;
                }

                $nomeProduto = $adicao->produto?->name ?? 'produto';

                // Garante a existência da linha de stock (atómico) antes do lock.
                StockInstallation::firstOrCreate(
                    ['installation_id' => $instalacaoId, 'product_id' => $adicao->product_id],
                    ['quantity' => 0, 'limite_minimo' => 0]
                );

                $stock = StockInstallation::query()
                    ->where('installation_id', $instalacaoId)
                    ->where('product_id', $adicao->product_id)
                    ->lockForUpdate()
                    ->first();

                $pedido = (float) $adicao->quantity;
                $disponivel = (float) $stock->quantity;
                $consumo = min($pedido, $disponivel);

                if ($pedido > $disponivel) {
                    $insuficientes[] = $nomeProduto;
                }

                if ($consumo > 0) {
                    $stock->quantity = $disponivel - $consumo;
                    $stock->save();

                    StockInstallationLog::create([
                        'stock_installation_id' => $stock->id,
                        'user_id' => auth()->id(),
                        'tipo_movimento' => 'consumo',
                        'quantity' => $consumo,
                        'created_at' => now(),
                    ]);
                }
            }
        });

        if ($insuficientes !== []) {
            $destinatarios = User::role('admin')->get();
            $corpo = 'Stock insuficiente na instalação '
                .($registo->piscina?->instalacao?->name ?? '')
                .' para: '.implode(', ', array_unique($insuficientes)).'.';

            Notification::make()
                ->warning()
                ->title('Stock insuficiente')
                ->body($corpo)
                ->send();

            if ($destinatarios->isNotEmpty()) {
                Notification::make()
                    ->warning()
                    ->title('Stock insuficiente')
                    ->body($corpo)
                    ->sendToDatabase($destinatarios);
            }
        }
    }

    /**
     * Se o registo tiver parâmetros fora dos limites legais CN 14/DA, notifica os
     * administradores (notificação persistente) com a piscina e os parâmetros.
     */
    private function notificarNaoConformidade(DailyRecord $registo): void
    {
        // A avaliação de temperatura precisa dos limites da piscina.
        if ($registo->piscina) {
            $registo->setRelation('piscina', $registo->piscina);
        }

        // Leituras em falta não são violações (evita "pH " vazio ou cloro
        // combinado negativo de registos parciais) — só avalia o que existe.
        $violacoes = [];
        if ($registo->ph !== null && ! $registo->phConforme()) {
            $violacoes[] = 'pH '.$registo->ph;
        }
        if ($registo->cloro_livre !== null && ! $registo->cloroLivreConforme()) {
            $violacoes[] = 'cloro livre '.$registo->cloro_livre.' mg/L';
        }
        if ($registo->cloro_total !== null && $registo->cloro_livre !== null && ! $registo->cloroCombinadoConforme()) {
            $violacoes[] = 'cloro combinado '.$registo->cloro_combinado.' mg/L';
        }
        if ($registo->temperatura !== null && ! $registo->temperaturaConforme()) {
            $violacoes[] = 'temperatura '.$registo->temperatura.' ºC';
        }

        if ($violacoes === []) {
            return;
        }

        $nome = $registo->piscina?->instalacao?->name
            ? $registo->piscina->instalacao->name.' '.$registo->piscina->name
            : ($registo->piscina?->name ?? 'piscina');

        $destinatarios = User::role('admin')->get();
        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Parâmetros fora dos limites: '.$nome)
            ->body(implode(' · ', $violacoes).'.')
            ->sendToDatabase($destinatarios);
    }
}
