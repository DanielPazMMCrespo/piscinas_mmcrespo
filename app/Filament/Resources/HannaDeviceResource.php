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
                    ->label('Configurar (site Hanna)')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('info')
                    ->url(fn (HannaDevice $record): string => 'https://www.hannacloud.com/deviceSettings/'.$record->hanna_device_id)
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('editar_setpoints')
                    ->label('Editar setpoints')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->color('warning')
                    ->visible(fn (HannaDevice $record): bool => $record->dosingSettingsParts() !== null)
                    ->fillForm(function (HannaDevice $record): array {
                        $ds = $record->dosingSettingsParts() ?? [];

                        return [
                            'ph_setpoint' => $ds[1] ?? null,
                            'ph_band' => $ds[2] ?? null,
                            'ph_overtime' => $ds[3] ?? null,
                            'orp_setpoint' => $ds[4] ?? null,
                            'orp_band' => $ds[5] ?? null,
                            'orp_overtime' => $ds[6] ?? null,
                        ];
                    })
                    ->form([
                        Forms\Components\TextInput::make('ph_setpoint')
                            ->label('pH Setpoint')->numeric()->step(0.01)->required(),
                        Forms\Components\TextInput::make('ph_band')
                            ->label('pH Banda Proporcional')->numeric()->step(0.01)->required(),
                        Forms\Components\TextInput::make('ph_overtime')
                            ->label('pH Overtime (min)')->numeric()->step(1)->required(),
                        Forms\Components\TextInput::make('orp_setpoint')
                            ->label('ORP Setpoint (mV)')->numeric()->step(1)->required(),
                        Forms\Components\TextInput::make('orp_band')
                            ->label('ORP Banda Proporcional (mV)')->numeric()->step(1)->required(),
                        Forms\Components\TextInput::make('orp_overtime')
                            ->label('ORP Overtime (min)')->numeric()->step(1)->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalHeading(fn (HannaDevice $record): string => 'Alterar setpoints — '.$record->name)
                    ->modalDescription('Isto escreve directamente no controlador físico da piscina (dosagem de pH/cloro). Confirma os valores com cuidado — os campos não editados aqui (tipo, caudais, atrasos) são preservados tal como estão.')
                    ->action(function (HannaDevice $record, array $data): void {
                        $parts = $record->dosingSettingsParts();
                        $as = $record->alarmSettingsRaw();
                        $gs = $record->generalSettingsRaw();

                        if ($parts === null || $as === null || $gs === null) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Não foi possível editar')
                                ->body('Faltam definições AS/GS/DS actuais — corre "Sincronizar agora" primeiro.')
                                ->send();

                            return;
                        }

                        $parts[1] = (string) $data['ph_setpoint'];
                        $parts[2] = (string) $data['ph_band'];
                        $parts[3] = (string) (int) $data['ph_overtime'];
                        $parts[4] = (string) $data['orp_setpoint'];
                        $parts[5] = (string) $data['orp_band'];
                        $parts[6] = (string) (int) $data['orp_overtime'];

                        $novoDs = implode(',', $parts);

                        try {
                            $hanna = app(\App\Services\HannaCloudService::class);
                            $hanna->authenticate(config('services.hanna.email'), config('services.hanna.password'));
                            $hanna->updateDeviceSettings($record->hanna_device_id, $as, $gs, $novoDs);

                            $confirmado = $hanna->getDeviceSettings($record->hanna_device_id);
                            if (! empty($confirmado)) {
                                $record->update(['raw_info' => $confirmado]);
                            }

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Setpoints actualizados')
                                ->body('Novo DS: '.$novoDs)
                                ->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->danger()
                                ->title('Falha ao escrever no dispositivo')
                                ->body($e->getMessage())
                                ->persistent()
                                ->send();
                        }
                    }),

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
