<?php declare(strict_types=1);
namespace App\Filament\Resources;


use App\Constants\IncidentStatus;
use App\Constants\IncidentType;
use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource\Pages;
use App\Models\Incident;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Registo Diário';

    protected static ?string $navigationLabel = 'Incidentes';

    protected static ?string $modelLabel = 'Incidente';

    protected static ?string $pluralModelLabel = 'Incidentes';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    /**
     * Reforça no Resource a mesma regra já validada em IncidentPolicy::create()
     * (defesa em profundidade — mantida sincronizada com a Policy).
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();
        return ($user?->hasAnyRole(UserRole::all()) ?? false) && $user->podeVer(NSPermission::INCIDENTES);
    }

    /**
     * Apenas o admin pode editar/eliminar incidentes. O pessoal de campo regista e consulta.
     */
    public static function canEdit($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('installation_id')
                    ->label('Instalação')
                    ->relationship(
                        'instalacao',
                        'name',
                        modifyQueryUsing: fn (Builder $query) => auth()->user()->hasRole(UserRole::NADADOR_SALVADOR)
                            ? $query->whereHas('piscinas', fn (Builder $q) => $q->whereIn('id', auth()->user()->piscinas()->pluck('pools.id')))
                            : $query,
                    )
                    ->required()
                    ->preload()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('pool_id', null)),
                Forms\Components\Select::make('pool_id')
                    ->label('Piscina (opcional)')
                    ->helperText('Deixe em branco se o incidente for da instalação toda (ex: fuga geral, avaria de bomba).')
                    ->relationship(
                        'piscina',
                        'name',
                        modifyQueryUsing: fn (Builder $query, Get $get) => $query
                            ->when($get('installation_id'), fn (Builder $q, $installationId) => $q->where('installation_id', $installationId))
                            ->when(
                                auth()->user()->hasRole(UserRole::NADADOR_SALVADOR),
                                fn (Builder $q) => $q->whereIn('id', auth()->user()->piscinas()->pluck('pools.id')),
                            ),
                    )
                    ->preload()
                    ->searchable()
                    ->disabled(fn (Get $get): bool => ! $get('installation_id')),
                Forms\Components\Select::make('user_id')
                    ->label('Técnico / Nadador-Salvador')
                    ->relationship('utilizador', 'name')
                    ->default(auth()->id())
                    ->required()
                    ->disabled()
                    ->dehydrated(),
                Forms\Components\DateTimePicker::make('ocorreu_em')
                    ->label('Data/Hora da Ocorrência')
                    ->default(now())
                    ->required(),
                Forms\Components\Select::make('type')
                    ->label('Tipo de Incidente')
                    ->options(IncidentType::options())
                    ->required(),
                Forms\Components\Textarea::make('descricao')
                    ->label('Descrição do Problema')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('observacoes')
                    ->label('Observações Adicionais')
                    ->columnSpanFull(),
                Forms\Components\Section::make('Resolução')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (?Incident $record): bool => $record?->status === IncidentStatus::RESOLVIDO)
                    ->columns(2)
                    ->schema([
                        Forms\Components\Placeholder::make('resolvido_em')
                            ->label('Resolvido em')
                            ->content(fn (?Incident $record): string => $record?->resolvido_em?->format('d/m/Y H:i') ?? '—'),
                        Forms\Components\Placeholder::make('resolvido_por')
                            ->label('Resolvido por')
                            ->content(fn (?Incident $record): string => $record?->resolvidoPor?->name ?? '—'),
                        Forms\Components\Textarea::make('resolucao')
                            ->label('Resolução aplicada')
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['instalacao', 'piscina', 'utilizador']))
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('instalacao.name')
                            ->label('Instalação')
                            ->weight('bold')
                            ->sortable()
                            ->searchable()
                            ->description(fn (Incident $record): ?string => $record->piscina?->name),
                        Tables\Columns\TextColumn::make('ocorreu_em')
                            ->label('Data/Hora')
                            ->dateTime('d/m/Y H:i')
                            ->color('gray')
                            ->size('sm')
                            ->sortable(),
                        Tables\Columns\TextColumn::make('utilizador.name')
                            ->label('Técnico/NS')
                            ->color('gray')
                            ->size('sm')
                            ->icon('heroicon-m-user')
                            ->sortable(),
                    ])->space(1),
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => IncidentType::label($state))
                            ->searchable(),
                        Tables\Columns\TextColumn::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'Resolvido' : 'Aberto')
                            ->color(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'success' : 'danger')
                            ->icon(fn (?string $state): string => $state === IncidentStatus::RESOLVIDO ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-circle'),
                    ])->space(1),
                ])->from('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([IncidentStatus::ABERTO => 'Aberto', IncidentStatus::RESOLVIDO => 'Resolvido'])
                    ->default(IncidentStatus::ABERTO),
            ], layout: \Filament\Tables\Enums\FiltersLayout::Modal)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    static::resolverTableAction(),
                    Tables\Actions\ViewAction::make(),
                    Tables\Actions\EditAction::make(),
                ])
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'view' => Pages\ViewIncident::route('/{record}'),
            'edit' => Pages\EditIncident::route('/{record}/edit'),
        ];
    }

    /**
     * Ação "Resolver" para a tabela de listagem. Partilha a lógica com
     * {@see resolverHeaderAction()} (usada na página de visualização) via
     * {@see aplicarResolucao()} — evita duplicar a regra de negócio nos dois sítios.
     */
    public static function resolverTableAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('resolver')
            ->label('Resolver')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Incident $record): bool => static::podeResolver($record))
            ->modalHeading('Resolver incidente')
            ->modalDescription('Descreva como foi resolvido. O incidente sai do quadro de operação.')
            ->modalSubmitActionLabel('Marcar como resolvido')
            ->form(static::resolverFormSchema())
            ->action(fn (Incident $record, array $data) => static::aplicarResolucao($record, $data));
    }

    /**
     * Ação "Resolver" para o cabeçalho de {@see Pages\ViewIncident}.
     */
    public static function resolverHeaderAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('resolver')
            ->label('Resolver')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Incident $record): bool => static::podeResolver($record))
            ->modalHeading('Resolver incidente')
            ->modalDescription('Descreva como foi resolvido. O incidente sai do quadro de operação.')
            ->modalSubmitActionLabel('Marcar como resolvido')
            ->form(static::resolverFormSchema())
            ->action(fn (Incident $record, array $data) => static::aplicarResolucao($record, $data));
    }

    private static function podeResolver(Incident $record): bool
    {
        return $record->status !== IncidentStatus::RESOLVIDO
            && (auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false);
    }

    /** @return array<\Filament\Forms\Components\Component> */
    private static function resolverFormSchema(): array
    {
        return [
            Forms\Components\Textarea::make('resolucao')
                ->label('Resolução aplicada')
                ->required()
                ->minLength(5)
                ->rows(3),
        ];
    }

    private static function aplicarResolucao(Incident $record, array $data): void
    {
        if (! static::podeResolver($record)) {
            Notification::make()->danger()->title('Sem permissão')->send();
            return;
        }

        $record->update([
            'status' => IncidentStatus::RESOLVIDO,
            'resolvido_em' => now(),
            'resolvido_por' => auth()->id(),
            'resolucao' => $data['resolucao'],
        ]);

        $texto = "Estado alterado para: Resolvido — {$data['resolucao']}";

        \App\Models\IncidentMessage::create([
            'incident_id' => $record->id,
            'user_id' => auth()->id(),
            'tipo' => \App\Models\IncidentMessage::TIPO_SISTEMA,
            'texto' => $texto,
        ]);

        \Illuminate\Support\Facades\Notification::send(
            $record->participantes(excluir: auth()->user()),
            new \App\Notifications\IncidentMessageNotification($record, auth()->user(), $texto)
        );

        Notification::make()
            ->success()
            ->title('Incidente resolvido')
            ->body('Saiu do quadro de operação.')
            ->send();
    }
}
