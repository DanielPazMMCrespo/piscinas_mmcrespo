<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HannaDeviceResource\Pages;
use App\Models\HannaDevice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Artisan;

/**
 * Recurso para gerir o mapeamento dispositivos Hanna Cloud → piscinas.
 * Apenas admin acede (informação de configuração de sistema).
 */
class HannaDeviceResource extends Resource
{
    protected static ?string $model = HannaDevice::class;

    protected static ?string $navigationIcon = 'heroicon-o-wifi';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'Sensor Hanna';

    protected static ?string $pluralModelLabel = 'Sensores Hanna';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasRole('admin');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('hanna_device_id')
                ->label('Device ID (DID)')
                ->required()
                ->helperText('Obtém o DID com: php artisan hanna:sync --discover'),

            Forms\Components\TextInput::make('name')
                ->label('Nome do dispositivo')
                ->required(),

            Forms\Components\Select::make('pool_id')
                ->label('Piscina associada')
                ->relationship('piscina', 'name')
                ->searchable()
                ->preload()
                ->helperText('A que piscina pertencem as leituras deste sensor?'),

            Forms\Components\Toggle::make('active')
                ->label('Activo (sincronizar)')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('hanna_device_id')
                    ->label('Device ID')->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('piscina.name')
                    ->label('Piscina')->sortable(),
                Tables\Columns\TextColumn::make('ultima_leitura')
                    ->label('Última leitura')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->state(fn (HannaDevice $r) => $r->ultimaLeitura()?->lida_em),
                Tables\Columns\TextColumn::make('ultima_ph')
                    ->label('pH')
                    ->badge()
                    ->state(fn (HannaDevice $r) => $r->ultimaLeitura()?->ph),
                Tables\Columns\TextColumn::make('ultima_temp')
                    ->label('T°C água')
                    ->state(function (HannaDevice $r) {
                        $v = $r->ultimaLeitura()?->temperatura_agua;

                        return $v !== null ? $v.' °C' : null;
                    }),
                Tables\Columns\IconColumn::make('active')
                    ->label('Activo')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('discover')
                    ->label('Descobrir dispositivos')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->action(function () {
                        Artisan::call('hanna:sync', ['--discover' => true]);
                    })
                    ->successNotificationTitle('Dispositivos actualizados — verifica a lista.')
                    ->requiresConfirmation()
                    ->modalHeading('Descobrir dispositivos Hanna Cloud')
                    ->modalDescription('Liga à Hanna Cloud e lista todos os dispositivos BL12x/BL13x associados à conta. Necessita de HANNA_EMAIL e HANNA_PASSWORD no .env.'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHannaDevices::route('/'),
            'create' => Pages\CreateHannaDevice::route('/create'),
            'edit' => Pages\EditHannaDevice::route('/{record}/edit'),
        ];
    }
}
