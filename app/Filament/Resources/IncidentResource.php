<?php declare(strict_types=1);
namespace App\Filament\Resources;


use App\Constants\UserRole;
use App\Filament\Resources\IncidentResource\Pages;
use App\Models\Incident;
use Filament\Forms;
use Filament\Forms\Form;
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
                    ->searchable(),
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
                    ->options([
                        'avaria_equipamento' => 'Avaria de Equipamento',
                        'fuga_agua' => 'Fuga de Água',
                        'qualidade_agua' => 'Problema na Qualidade da Água',
                        'outro' => 'Outro',
                    ])
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
                    ->visible(fn (?Incident $record): bool => $record?->status === 'resolvido')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('resolvido_em')
                            ->label('Resolvido em')
                            ->disabled(),
                        Forms\Components\TextInput::make('resolvidoPor.name')
                            ->label('Resolvido por')
                            ->disabled(),
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['instalacao', 'utilizador']))
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('instalacao.name')
                            ->label('Instalação')
                            ->weight('bold')
                            ->sortable()
                            ->searchable(),
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
                            ->searchable(),
                        Tables\Columns\TextColumn::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => $state === 'resolvido' ? 'Resolvido' : 'Aberto')
                            ->color(fn (?string $state): string => $state === 'resolvido' ? 'success' : 'danger')
                            ->icon(fn (?string $state): string => $state === 'resolvido' ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-circle'),
                    ])->space(1),
                ])->from('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(['aberto' => 'Aberto', 'resolvido' => 'Resolvido'])
                    ->default('aberto'),
            ], layout: \Filament\Tables\Enums\FiltersLayout::Modal)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('resolver')
                        ->label('Resolver')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (Incident $record): bool => $record->status !== 'resolvido' && auth()->user()->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]))
                        ->modalHeading('Resolver incidente')
                        ->modalDescription('Descreva como foi resolvido. O incidente sai do quadro de operação.')
                        ->modalSubmitActionLabel('Marcar como resolvido')
                        ->form([
                            Forms\Components\Textarea::make('resolucao')
                                ->label('Resolução aplicada')
                                ->required()
                                ->minLength(5)
                                ->rows(3),
                        ])
                        ->action(function (Incident $record, array $data): void {
                            if (! auth()->user()->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
                                Notification::make()->danger()->title('Sem permissão')->send();
                                return;
                            }

                            $record->update([
                                'status' => 'resolvido',
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
                        }),
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
}
