<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Filament\Resources\DailyRecordResource;
use App\Models\DailyRecord;
use App\Models\Pool;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateDailyRecord extends CreateRecord
{
    protected static string $resource = DailyRecordResource::class;

    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function piscinaIdsOrdenadas(array $data): array
    {
        return array_values(array_filter(array_unique(array_merge(
            [$data['pool_id'] ?? null],
            $data['outras_piscinas_visita'] ?? []
        ))));
    }

    private function conteudoModalConfirmacao()
    {
        $data = $this->data;
        $problemas = [];

        $poolIds = $this->piscinaIdsOrdenadas($data);
        foreach ($poolIds as $slot => $pId) {
            $pool = Pool::find($pId);
            $poolData = $data['piscinas'][$slot] ?? [];

            foreach (['ph', 'cloro_livre', 'temperatura', 'transparencia'] as $campo) {
                if (isset($poolData[$campo]) && $poolData[$campo] !== '') {
                    $estado = DailyRecord::avaliarConformidade($campo, $poolData[$campo], $pool);
                    if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                        $problemas[] = ($pool ? $pool->name . ': ' : '') . $estado['mensagem'];
                    }
                }
            }
            if (isset($poolData['cloro_livre'], $poolData['cloro_total']) && $poolData['cloro_livre'] !== '' && $poolData['cloro_total'] !== '') {
                $combinado = (float)$poolData['cloro_total'] - (float)$poolData['cloro_livre'];
                $estado = DailyRecord::avaliarConformidade('cloro_combinado', $combinado, $pool);
                if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                    $problemas[] = ($pool ? $pool->name . ': ' : '') . $estado['mensagem'];
                }
            }
        }

        return view('filament.daily-record-modal-summary', ['problemas' => $problemas]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('Criar')
                ->action(fn () => $this->create())
                ->requiresConfirmation()
                ->modalHeading('Confirmar registo')
                ->modalContent(fn () => $this->conteudoModalConfirmacao())
                ->modalSubmitActionLabel('Confirmar e guardar')
                ->keyBindings(['mod+s']),
            $this->getCancelFormAction(),
        ];
    }

    public function getCachedFormActions(): array
    {
        return $this->getFormActions();
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $poolIds = $this->piscinaIdsOrdenadas($data);
        $firstRecord = null;

        $baseData = [
            'user_id' => $data['user_id'],
            'registado_em' => $data['registado_em'],
            'ns_foto' => $data['ns_foto'] ?? null,
        ];

        foreach ($poolIds as $slot => $pId) {
            $slotData = $data['piscinas'][$slot] ?? [];
            $adicoes = $slotData['adicoes'] ?? [];
            unset($slotData['adicoes'], $slotData['pool_id']);

            $recordData = array_merge($baseData, $slotData, ['pool_id' => $pId]);

            $record = DailyRecord::create($recordData);

            foreach ($adicoes as $adicao) {
                $record->adicoes()->create($adicao);
            }

            if (!$firstRecord) {
                $firstRecord = $record;
            }

            \App\Jobs\ProcessDailyRecordAfterCreate::dispatch($record->id, (int) auth()->id());
        }

        app(\App\Services\CacheService::class)->invalidateAlerts(auth()->id());

        return $firstRecord ?? new DailyRecord();
    }

    protected function afterCreate(): void
    {
        // Handled in handleRecordCreation
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
