<?php declare(strict_types=1);
namespace App\Filament\Resources;


use App\Filament\Resources\HannaDeviceResource\Pages;
use App\Models\HannaDevice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
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
                ->disabled(fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(fn (string $operation): bool => $operation !== 'edit')
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
            ->recordAction('ver_detalhes')
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
                Tables\Actions\Action::make('sync_now')
                    ->label('Sincronizar agora')
                    ->icon('heroicon-o-arrow-path')
                    ->color('primary')
                    ->action(function (Tables\Actions\Action $action): void {
                        $exitCode = Artisan::call('hanna:sync');
                        $output = preg_replace('/\x1B\[[0-9;]*[mGKHF]/u', '', trim(Artisan::output()));

                        if ($exitCode === 0) {
                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Sync concluído')
                                ->body($output ?: 'Leituras actualizadas.')
                                ->send();
                            $action->redirect(filament()->getUrl());
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Falha na sincronização')
                                ->body($output ?: 'Verifica HANNA_CLOUD_EMAIL e HANNA_CLOUD_PASSWORD no .env.')
                                ->persistent()
                                ->send();
                        }
                    }),
                Tables\Actions\Action::make('discover')
                    ->label('Descobrir dispositivos')
                    ->icon('heroicon-o-magnifying-glass')
                    ->color('gray')
                    ->action(function (): void {
                        $exitCode = Artisan::call('hanna:sync', ['--discover' => true]);
                        $output = preg_replace('/\x1B\[[0-9;]*[mGKHF]/u', '', trim(Artisan::output()));

                        if ($exitCode === 0) {
                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Dispositivos actualizados')
                                ->body($output ?: 'Verifica a lista abaixo.')
                                ->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Falha ao descobrir dispositivos')
                                ->body($output ?: 'Verifica as credenciais no .env.')
                                ->persistent()
                                ->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Descobrir dispositivos Hanna Cloud')
                    ->modalDescription('Liga à Hanna Cloud e lista todos os dispositivos BL12x/BL13x associados à conta. Necessita de HANNA_CLOUD_EMAIL e HANNA_CLOUD_PASSWORD no .env.'),
            ])
            ->actions([
                Tables\Actions\Action::make('ver_detalhes')
                    ->label('Detalhes')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (HannaDevice $record): string => $record->name)
                    ->modalContent(fn (HannaDevice $record): View => view('filament.hanna-device-modal', ['device' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalWidth(\Filament\Support\Enums\MaxWidth::ThreeExtraLarge),

                Tables\Actions\Action::make('hanna_settings')
                    ->label('Configurar')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('info')
                    ->url(fn (HannaDevice $record): string => 'https://www.hannacloud.com/deviceSettings/'.$record->hanna_device_id)
                    ->openUrlInNewTab(),

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
