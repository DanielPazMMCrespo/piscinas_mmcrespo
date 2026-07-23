<?php declare(strict_types=1);

namespace App\Filament\Resources\HannaDeviceResource\Pages;

use App\Filament\Resources\HannaDeviceResource;
use Filament\Actions;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewHannaDevice extends ViewRecord
{
    protected static string $resource = HannaDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Informações do Dispositivo')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('hanna_device_id')
                            ->label('Device ID (DID)'),
                        TextEntry::make('name')
                            ->label('Nome'),
                        TextEntry::make('piscina.nome_completo')
                            ->label('Piscina'),
                        TextEntry::make('active')
                            ->label('Ativo')
                            ->badge()
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                            ->formatStateUsing(fn (bool $state): string => $state ? 'Sim' : 'Não'),
                    ]),
                ]),

            Section::make('Última Leitura')
                ->schema([
                    Grid::make(2)->schema([
                        TextEntry::make('ultima_ph')
                            ->label('pH')
                            ->state(fn ($record) => $record->ultimaLeitura()?->ph),
                        TextEntry::make('ultima_orp')
                            ->label('ORP (mV)')
                            ->state(fn ($record) => $record->ultimaLeitura()?->orp),
                        TextEntry::make('ultima_temp')
                            ->label('Temperatura (°C)')
                            ->state(fn ($record) => $record->ultimaLeitura()?->temperatura_agua),
                        TextEntry::make('lida_em')
                            ->label('Hora da leitura')
                            ->state(fn ($record) => $record->ultimaLeitura()?->lida_em?->format('d/m/Y H:i'))
                            ->visible(fn ($record) => $record->ultimaLeitura() !== null),
                    ]),
                ]),
        ]);
    }
}
