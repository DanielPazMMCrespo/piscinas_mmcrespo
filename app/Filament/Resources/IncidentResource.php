<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IncidentResource\Pages;
use App\Models\Incident;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Operação';
    protected static ?string $modelLabel = 'Incidente';
    protected static ?string $pluralModelLabel = 'Incidentes';

    /**
     * Apenas o admin pode editar/eliminar incidentes. O pessoal de campo regista e consulta.
     */
    public static function canEdit($record): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('installation_id')
                    ->label('Instalação')
                    ->relationship('instalacao', 'name')
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
                        'fuga_agua'          => 'Fuga de Água',
                        'qualidade_agua'     => 'Problema na Qualidade da Água',
                        'outro'              => 'Outro',
                    ])
                    ->required(),
                Forms\Components\Textarea::make('descricao')
                    ->label('Descrição do Problema')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('observacoes')
                    ->label('Observações Adicionais')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('instalacao.name')
                    ->label('Instalação')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Técnico/NS')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('ocorreu_em')
                    ->label('Data/Hora')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
            'index'  => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'view'   => Pages\ViewIncident::route('/{record}'),
            'edit'   => Pages\EditIncident::route('/{record}/edit'),
        ];
    }
}
