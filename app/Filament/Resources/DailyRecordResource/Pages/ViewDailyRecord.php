<?php

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
                    ->visible(fn () => $this->record->fotos()->exists() || filled($this->record->contador_foto)
                        || filled($this->record->bomba_foto) || filled($this->record->tanque_foto))
                    ->schema([
                        Grid::make(columns: 2)
                            ->schema([
                                Section::make('Foto do Contador')
                                    ->visible(fn () => filled($this->record->contador_foto))
                                    ->schema([
                                        ImageEntry::make('contador_foto')
                                            ->label('')
                                            ->disk('local'),
                                    ]),
                                Section::make('Foto da Bomba')
                                    ->visible(fn () => filled($this->record->bomba_foto))
                                    ->schema([
                                        ImageEntry::make('bomba_foto')
                                            ->label('')
                                            ->disk('local'),
                                    ]),
                                Section::make('Foto do Tanque')
                                    ->visible(fn () => filled($this->record->tanque_foto))
                                    ->schema([
                                        ImageEntry::make('tanque_foto')
                                            ->label('')
                                            ->disk('local'),
                                    ]),
                                Section::make('Análises (até 5)')
                                    ->visible(fn () => $this->record->fotos()->exists())
                                    ->schema([
                                        Grid::make(columns: 3)
                                            ->schema([
                                                ImageEntry::make('fotos')
                                                    ->label('')
                                                    ->disk('local')
                                                    ->getStateUsing(function () {
                                                        return $this->record->fotos()
                                                            ->pluck('path')
                                                            ->map(fn ($path) => $path)
                                                            ->toArray();
                                                    }),
                                            ]),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
