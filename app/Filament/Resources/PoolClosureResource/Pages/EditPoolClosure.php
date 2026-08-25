<?php

declare(strict_types=1);

namespace App\Filament\Resources\PoolClosureResource\Pages;

use App\Filament\Resources\PoolClosureResource;
use App\Models\PoolClosure;
use App\Services\PlanoParagemPdfService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EditPoolClosure extends EditRecord
{
    protected static string $resource = PoolClosureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('descarregarPlano')
                ->label('Plano (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function (): StreamedResponse {
                    /** @var PoolClosure $record */
                    $record = $this->getRecord();

                    return app(PlanoParagemPdfService::class)->streamPlano($record);
                }),

            Actions\Action::make('descarregarRelatorio')
                ->label('Relatório (PDF)')
                ->icon('heroicon-o-document-chart-bar')
                ->color('primary')
                ->action(function (): StreamedResponse {
                    /** @var PoolClosure $record */
                    $record = $this->getRecord();

                    return app(PlanoParagemPdfService::class)->streamRelatorio($record);
                }),

            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
