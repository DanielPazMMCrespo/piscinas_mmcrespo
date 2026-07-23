<?php

declare(strict_types=1);

namespace App\Filament\Resources\StockInstallationResource\Pages;

use App\Filament\Resources\StockInstallationResource;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditStockInstallation extends EditRecord
{
    protected static string $resource = StockInstallationResource::class;

    private float $quantidadeAnterior = 0.0;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $fresh = StockInstallation::lockForUpdate()->findOrFail($record->id);
            $this->quantidadeAnterior = (float) $fresh->quantity;

            $fresh->fill($data);
            if ((float) $fresh->quantity < 0) {
                $fresh->quantity = 0;
            }
            $fresh->save();

            return $fresh;
        });
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        $delta = round((float) $record->quantity - $this->quantidadeAnterior, 3);

        if ($delta === 0.0) {
            return;
        }

        StockInstallationLog::create([
            'stock_installation_id' => $record->id,
            'user_id' => auth()->id(),
            'tipo_movimento' => $delta > 0 ? 'entrada' : 'saida',
            'quantity' => abs($delta),
            'observacoes' => 'Edição direta (ajuste de inventário)',
            'created_at' => now(),
        ]);
    }
}
