<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HannaDeviceResource\Pages;
use App\Models\HannaDevice;
use App\Models\OperationalAction;
use App\Models\SensorOutage;
use App\Services\SettingsService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Recurso para gerir o mapeamento dispositivos Hanna Cloud → piscinas.
 * Apenas admin acede (informação de configuração de sistema).
 */
class HannaDeviceResource extends Resource
{
    protected static ?string $model = HannaDevice::class;

    protected static ?string $navigationIcon = 'heroicon-o-wifi';

    protected static ?string $navigationGroup = 'Sistema';

    /** Sem isto o título do resultado na pesquisa global era o nome do modelo, não o registo. */
    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Sensor Hanna';

    protected static ?string $pluralModelLabel = 'Sensores Hanna';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasRole('admin');
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'hanna_device_id', 'piscina.name'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Piscina' => $record->piscina?->name ?? '—'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('piscina');
    }

    /**
     * Avaria em aberto da sonda desta linha, memoizada por piscina: a coluna de
     * estado, a cor e a descrição perguntam todas pela mesma linha, e a tabela
     * refaz-nas a cada poll.
     *
     * @var array<int, SensorOutage|null>
     */
    private static array $avariasMemo = [];

    private static function avaria(HannaDevice $device): ?SensorOutage
    {
        if ($device->pool_id === null) {
            return null;
        }

        return self::$avariasMemo[$device->pool_id]
            ??= SensorOutage::abertaPara((int) $device->pool_id);
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

            Forms\Components\TextInput::make('ajuste_minutos')
                ->label('Ajuste de relógio (minutos)')
                ->numeric()
                ->default(0)
                ->required()
                ->minValue(-1440)
                ->maxValue(1440)
                ->helperText('Minutos a somar à hora que o controlador reporta. Deixa 0 se a hora dele está certa. Se ele reportar 2 horas adiantado, põe -120.'),

            Forms\Components\Toggle::make('active')
                ->label('Activo (sincronizar)')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->recordAction('ver_detalhes')
            ->columns([
                Tables\Columns\TextColumn::make('hanna_device_id')
                    ->label('Device ID')->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('piscina.name')
                    ->label('Piscina')->sortable(),
                Tables\Columns\TextColumn::make('estado_sonda')
                    ->label('Estado')
                    ->badge()
                    // Uma avaria reportada por ação operacional é uma causa
                    // conhecida: vale mais que o diagnóstico automático "em falha".
                    ->state(function (HannaDevice $r): string {
                        $avaria = self::avaria($r);

                        return $avaria !== null ? $avaria->motivoLabel() : 'Em serviço';
                    })
                    ->color(fn (HannaDevice $r): string => self::avaria($r) !== null ? 'warning' : 'success')
                    ->icon(fn (HannaDevice $r): string => self::avaria($r) !== null ? 'heroicon-o-signal-slash' : 'heroicon-o-signal')
                    ->description(function (HannaDevice $r): ?string {
                        $avaria = self::avaria($r);

                        if ($avaria === null) {
                            return null;
                        }

                        return 'desde '.$avaria->aberta_em->format('d/m/Y H:i')
                            .($avaria->detalhe !== null ? ' — '.Str::limit($avaria->detalhe, 60) : '');
                    }),
                Tables\Columns\TextColumn::make('ultima_leitura')
                    ->label('Última leitura')
                    ->dateTime('d/m/Y H:i')
                    // "31/07 23:29" obrigava a subtrair de cabeça para saber se a
                    // sonda está a falhar; o estado vem do timeout configurado.
                    ->description(function (HannaDevice $r): string {
                        $leitura = $r->ultimaLeitura();
                        $avaria = self::avaria($r);

                        if ($leitura === null) {
                            return $avaria !== null ? 'sem leituras — '.mb_strtolower($avaria->motivoLabel()) : 'sem leituras';
                        }

                        $timeout = app(SettingsService::class)->getInt('sensor_timeout_minutos', 60);
                        $minutos = (int) abs($leitura->lida_em->diffInMinutes(now()));

                        $estado = match (true) {
                            $avaria !== null => ' — leituras não fiáveis ('.mb_strtolower($avaria->motivoLabel()).')',
                            $minutos > $timeout => ' — sonda em falha',
                            default => ' — online',
                        };

                        return $leitura->lida_em->locale('pt')->diffForHumans().$estado;
                    })
                    ->badge()
                    ->color(function (HannaDevice $r): string {
                        $leitura = $r->ultimaLeitura();

                        if (self::avaria($r) !== null) {
                            return 'warning';
                        }

                        if ($leitura === null) {
                            return 'danger';
                        }

                        $timeout = app(SettingsService::class)->getInt('sensor_timeout_minutos', 60);

                        return abs($leitura->lida_em->diffInMinutes(now())) > $timeout ? 'danger' : 'success';
                    })
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
                            Notification::make()
                                ->success()
                                ->title('Sync concluído')
                                ->body($output ?: 'Leituras actualizadas.')
                                ->send();
                            $action->redirect(filament()->getUrl());
                        } else {
                            Notification::make()
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
                            Notification::make()
                                ->success()
                                ->title('Dispositivos actualizados')
                                ->body($output ?: 'Verifica a lista abaixo.')
                                ->send();
                        } else {
                            Notification::make()
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
            ->filters([
                Tables\Filters\Filter::make('em_falha')
                    ->label('Só sondas em falha')
                    ->toggle()
                    ->query(function ($query) {
                        $timeout = app(SettingsService::class)->getInt('sensor_timeout_minutos', 60);
                        $limite = now()->subMinutes($timeout);

                        return $query->where(function ($q) use ($limite) {
                            $q->whereDoesntHave('leituras')
                                ->orWhereDoesntHave('leituras', fn ($sub) => $sub->where('lida_em', '>=', $limite));
                        });
                    }),
                Tables\Filters\Filter::make('com_avaria')
                    ->label('Só sondas com avaria reportada')
                    ->toggle()
                    ->query(fn ($query) => $query->whereIn(
                        'pool_id',
                        SensorOutage::query()->abertas()->select('pool_id')
                    )),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('ver_detalhes')
                    ->label('Detalhes')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (HannaDevice $record): string => $record->name)
                    ->modalContent(fn (HannaDevice $record): View => view('filament.hanna-device-modal', ['device' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalWidth(MaxWidth::ThreeExtraLarge),

                Tables\Actions\Action::make('estado_sonda')
                    ->label(fn (HannaDevice $record): string => self::avaria($record) !== null
                        ? 'Atualizar / dar baixa'
                        : 'Reportar avaria')
                    ->icon('heroicon-o-signal-slash')
                    ->color('warning')
                    // A avaria vive numa ação operacional (é o registo do que se
                    // passou, com autor e hora); aqui é só o atalho para a criar.
                    ->visible(fn (HannaDevice $record): bool => $record->pool_id !== null
                        && OperationalActionResource::canCreate())
                    ->url(fn (HannaDevice $record): string => OperationalActionResource::getUrl('create', [
                        'pool' => $record->pool_id,
                        'tipo' => OperationalAction::TIPO_AVARIA_SONDA,
                    ])),

                Tables\Actions\Action::make('hanna_settings')
                    ->label('Configurar (site Hanna)')
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
            'view' => Pages\ViewHannaDevice::route('/{record}'),
            'edit' => Pages\EditHannaDevice::route('/{record}/edit'),
        ];
    }
}
