<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource\Pages;


use App\Filament\Resources\DailyRecordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailyRecord extends EditRecord
{
    protected static string $resource = DailyRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()
                ->requiresConfirmation()
                ->modalHeading('Confirmar alterações')
                ->modalContent(function () {
                    $data = $this->data;
                    $pool = \App\Models\Pool::find($data['pool_id'] ?? null);
                    $problemas = [];
                    foreach (['ph', 'cloro_livre', 'temperatura', 'transparencia'] as $campo) {
                        if (isset($data[$campo]) && $data[$campo] !== '') {
                            $estado = \App\Models\DailyRecord::avaliarConformidade($campo, $data[$campo], $pool);
                            if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                                $problemas[] = $estado['mensagem'];
                            }
                        }
                    }
                    if (isset($data['cloro_livre'], $data['cloro_total']) && $data['cloro_livre'] !== '' && $data['cloro_total'] !== '') {
                        $combinado = (float)$data['cloro_total'] - (float)$data['cloro_livre'];
                        $estado = \App\Models\DailyRecord::avaliarConformidade('cloro_combinado', $combinado, $pool);
                        if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                            $problemas[] = $estado['mensagem'];
                        }
                    }
                    return view('filament.daily-record-modal-summary', ['problemas' => $problemas]);
                })
                ->modalSubmitActionLabel('Confirmar e guardar'),
            $this->getCancelFormAction(),
        ];
    }
}
