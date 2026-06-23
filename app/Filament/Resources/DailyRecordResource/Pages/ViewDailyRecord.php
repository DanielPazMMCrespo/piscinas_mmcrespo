<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyRecordResource\Pages;

use App\Filament\Resources\DailyRecordResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewDailyRecord extends ViewRecord
{
    protected static string $resource = DailyRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make('Fotos do Registo')
                    ->visible(fn () => ! empty($this->record->analises_fotos)
                        || $this->record->fotos()->exists()
                        || filled($this->record->contador_foto)
                        || filled($this->record->bomba_foto)
                        || filled($this->record->tanque_foto)
                        || filled($this->record->ns_foto))
                    ->schema([
                        Grid::make(columns: 2)
                            ->schema([
                                Section::make('Foto do Contador')
                                    ->visible(fn () => filled($this->record->contador_foto))
                                    ->schema([
                                        ImageEntry::make('contador_foto')
                                            ->label('')
                                            ->disk('public'),
                                    ]),
                                Section::make('Foto da Bomba')
                                    ->visible(fn () => filled($this->record->bomba_foto))
                                    ->schema([
                                        ImageEntry::make('bomba_foto')
                                            ->label('')
                                            ->disk('public'),
                                    ]),
                                Section::make('Foto do Tanque')
                                    ->visible(fn () => filled($this->record->tanque_foto))
                                    ->schema([
                                        ImageEntry::make('tanque_foto')
                                            ->label('')
                                            ->disk('public'),
                                    ]),
                                Section::make('Foto Análise NS')
                                    ->visible(fn () => filled($this->record->ns_foto))
                                    ->schema([
                                        ImageEntry::make('ns_foto')
                                            ->label('')
                                            ->disk('public'),
                                    ]),
                            ]),
                        Section::make('Fotos das Análises')
                            ->visible(fn () => ! empty($this->record->analises_fotos) || $this->record->fotos()->exists())
                            ->schema([
                                ImageEntry::make('analises_fotos')
                                    ->label('')
                                    ->disk('public')
                                    ->getStateUsing(function (): array {
                                        $fromJson = $this->record->analises_fotos ?? [];
                                        if (! empty($fromJson)) {
                                            return $fromJson;
                                        }

                                        return $this->record->fotos()->pluck('path')->toArray();
                                    }),
                            ]),
                    ]),
            ]);
    }
}
