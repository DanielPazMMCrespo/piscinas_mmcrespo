<?php declare(strict_types=1);
namespace App\Filament\Resources;

use App\Constants\UserRole;
use App\Filament\Resources\CustomBroadcastResource\Pages;
use App\Models\CustomBroadcast;
use App\Models\NotificationTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Anúncios compostos por um admin e enviados por push+sino aos cargos
 * escolhidos — envio único numa data/hora exata, ou diário recorrente.
 */
class CustomBroadcastResource extends Resource
{
    protected static ?string $model = CustomBroadcast::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Notificações Personalizadas';

    protected static ?string $modelLabel = 'Notificação Personalizada';

    protected static ?string $pluralModelLabel = 'Notificações Personalizadas';

    protected static ?int $navigationSort = 12;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    private static function rotulosCargos(): array
    {
        return [
            UserRole::ADMIN => 'Admin',
            UserRole::GESTOR => 'Gestor',
            UserRole::TECNICO => 'Técnico',
            UserRole::NADADOR_SALVADOR => 'Nadador-Salvador',
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Mensagem')
                    ->schema([
                        Forms\Components\Select::make('template_picker')
                            ->label('Usar modelo (opcional)')
                            ->options(fn () => NotificationTemplate::query()->pluck('nome', 'id'))
                            ->helperText('Escolher um modelo só pré-preenche o título/mensagem — continuas a poder editar antes de guardar.')
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (! $state) {
                                    return;
                                }
                                $template = NotificationTemplate::find($state);
                                if ($template) {
                                    $set('titulo', $template->titulo);
                                    $set('corpo', $template->corpo);
                                }
                            }),
                        Forms\Components\TextInput::make('titulo')
                            ->label('Título')
                            ->required()
                            ->maxLength(150),
                        Forms\Components\Textarea::make('corpo')
                            ->label('Mensagem')
                            ->required()
                            ->rows(3),
                    ]),

                Forms\Components\Section::make('Destinatários')
                    ->schema([
                        Forms\Components\CheckboxList::make('cargos')
                            ->label('Enviar para')
                            ->options(self::rotulosCargos())
                            ->required()
                            ->columns(2),
                    ]),

                Forms\Components\Section::make('Quando')
                    ->schema([
                        Forms\Components\Radio::make('tipo_agendamento')
                            ->label('Tipo de envio')
                            ->options([
                                CustomBroadcast::TIPO_UNICO => 'Envio único (data/hora exata)',
                                CustomBroadcast::TIPO_DIARIO => 'Diário (recorrente, a uma hora fixa)',
                            ])
                            ->default(CustomBroadcast::TIPO_UNICO)
                            ->live()
                            ->required(),
                        Forms\Components\DateTimePicker::make('enviar_em')
                            ->label('Enviar em')
                            ->native(false)
                            ->minDate(now())
                            ->required(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_UNICO)
                            ->visible(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_UNICO),
                        Forms\Components\TimePicker::make('hora_diaria')
                            ->label('Hora do dia')
                            ->seconds(false)
                            ->required(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_DIARIO)
                            ->visible(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_DIARIO),
                        Forms\Components\Toggle::make('ativo')
                            ->label('Ativo')
                            ->helperText('Desativa para pausar os envios diários sem apagar a notificação.')
                            ->default(true)
                            ->visible(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_DIARIO),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('titulo')
                    ->label('Título')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cargos')
                    ->label('Cargos')
                    ->formatStateUsing(fn (array $state): string => collect($state)
                        ->map(fn (string $cargo) => self::rotulosCargos()[$cargo] ?? $cargo)
                        ->join(', ')),
                Tables\Columns\TextColumn::make('tipo_agendamento')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state): string => $state === CustomBroadcast::TIPO_DIARIO ? 'Diário' : 'Único'),
                Tables\Columns\TextColumn::make('quando')
                    ->label('Quando')
                    ->state(fn (CustomBroadcast $record): string => $record->tipo_agendamento === CustomBroadcast::TIPO_DIARIO
                        ? 'Todos os dias às ' . \Illuminate\Support\Carbon::parse($record->hora_diaria)->format('H:i')
                        : ($record->enviar_em?->format('d/m/Y H:i') ?? '—')),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->state(function (CustomBroadcast $record): string {
                        if ($record->tipo_agendamento === CustomBroadcast::TIPO_DIARIO) {
                            return $record->ativo ? 'Ativo' : 'Pausado';
                        }

                        return $record->enviado_em ? 'Enviado' : 'Agendado';
                    })
                    ->badge()
                    ->color(function (CustomBroadcast $record): string {
                        if ($record->tipo_agendamento === CustomBroadcast::TIPO_DIARIO) {
                            return $record->ativo ? 'success' : 'gray';
                        }

                        return $record->enviado_em ? 'success' : 'warning';
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomBroadcasts::route('/'),
            'create' => Pages\CreateCustomBroadcast::route('/create'),
            'edit' => Pages\EditCustomBroadcast::route('/{record}/edit'),
        ];
    }
}
