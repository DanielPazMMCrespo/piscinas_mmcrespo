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
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateDailyRecord extends CreateRecord
{
    protected static string $resource = DailyRecordResource::class;

    protected static string $view = 'filament.resources.daily-records.pages.create-daily-record';

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

    protected function beforeValidate(): void
    {
        if (isset($this->data['pools']) && is_array($this->data['pools'])) {
            foreach ($this->data['pools'] as $poolId => $poolData) {
                if (is_array($poolData)) {
                    foreach ($poolData as $k => $v) {
                        if (is_string($v) && str_contains($v, ',')) {
                            $this->data['pools'][$poolId][$k] = str_replace(',', '.', $v);
                        }
                    }
                }
            }
        }
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
            $this->beforeValidate();
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

            Log::error('Erro ao criar registo diário: '.$exception->getMessage(), [
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

        if ($another) {
            Notification::make()
                ->success()
                ->title('Registo guardado com sucesso!')
                ->body('O formulário foi reiniciado para um novo registo.')
                ->send();
        } else {
            Notification::make()
                ->success()
                ->title('Registo guardado!')
                ->body('Os parâmetros das piscinas foram registados com sucesso.')
                ->send();

            $this->redirect('/admin');
        }

        $this->dispatch('dailyRecordSaved');
    }

    public function validarERegistosGuardar(bool $another = false): void
    {
        $this->beforeValidate();
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

        $this->create($another);
    }

    /**
     * IDs das piscinas selecionadas para registo.
     * Devolve null se a chave não existir no payload (ex.: formulário de piscina única).
     *
     * @return array<int, int>|null
     */
    private function getPiscinasSelecionadasIds(?array $data = null): ?array
    {
        $selecionadas = ($data ?? $this->data)['piscinas_selecionadas'] ?? null;
        if ($selecionadas === null) {
            return null;
        }

        return array_values(array_map('intval', (array) $selecionadas));
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $selecionadas = $this->getPiscinasSelecionadasIds($data);

        if ($selecionadas !== null && isset($data['pools']) && is_array($data['pools'])) {
            $data['pools'] = array_filter(
                $data['pools'],
                fn ($poolData, $poolId) => in_array((int) $poolId, $selecionadas, true),
                ARRAY_FILTER_USE_BOTH
            );
        }

        return $data;
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
        $selecionadas = $this->getPiscinasSelecionadasIds();

        foreach (($this->data['pools'] ?? []) as $poolId => $dadosPiscina) {
            if ($selecionadas !== null && ! in_array((int) $poolId, $selecionadas, true)) {
                continue;
            }

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
        $selecionadas = $this->getPiscinasSelecionadasIds($data);
        $hasErrors = false;

        foreach ($pools as $poolId => $poolData) {
            if ($selecionadas !== null && ! in_array((int) $poolId, $selecionadas, true)) {
                continue;
            }

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
                ->hidden(function (): bool {
                    $pools = $this->data['pools'] ?? [];
                    if (blank($pools)) {
                        return true;
                    }

                    $selecionadas = $this->getPiscinasSelecionadasIds();
                    if ($selecionadas !== null && blank($selecionadas)) {
                        return true;
                    }

                    return false;
                }),
            Action::make('createAnother')
                ->label('Gravar e Novo')
                ->icon('heroicon-o-plus-circle')
                ->color('gray')
                ->action(fn () => $this->validarERegistosGuardar(another: true))
                ->hidden(function (): bool {
                    $pools = $this->data['pools'] ?? [];
                    if (blank($pools)) {
                        return true;
                    }

                    $selecionadas = $this->getPiscinasSelecionadasIds();
                    if ($selecionadas !== null && blank($selecionadas)) {
                        return true;
                    }

                    return false;
                }),
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
                    $selecionadas = $this->getPiscinasSelecionadasIds();
                    $valores = [];

                    foreach ($poolsData as $poolId => $poolData) {
                        if ($selecionadas !== null && ! in_array((int) $poolId, $selecionadas, true)) {
                            continue;
                        }

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
