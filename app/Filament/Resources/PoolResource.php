<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PoolResource\Pages;
use App\Models\Pool;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PoolResource extends Resource
{
    protected static ?string $model = Pool::class;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Estrutura';

    protected static ?string $modelLabel = 'Piscina';

    protected static ?string $pluralModelLabel = 'Piscinas';

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('admin');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('installation_id')
                    ->label('InstalaÃ§Ã£o')
                    ->relationship('instalacao', 'name')
                    ->required()
                    ->preload()
                    ->searchable(),
                Forms\Components\TextInput::make('name')
                    ->label('Nome da Piscina')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('type')
                    ->label('Tipo (ex: Interior, Exterior, Infantil)')
                    ->required()
                    ->maxLength(50),
                Forms\Components\TextInput::make('temp_min')
                    ->label('Temperatura MÃ­nima (ÂºC)')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('temp_max')
                    ->label('Temperatura MÃ¡xima (ÂºC)')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('volume')
                    ->label('Volume (mÂ³)')
                    ->helperText('NecessÃ¡rio para a calculadora de dosagem de quÃ­micos.')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->suffix('mÂ³'),
                Forms\Components\Toggle::make('active')
                    ->label('Ativo')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('instalacao.name')
                    ->label('InstalaÃ§Ã£o')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Piscina')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('temp_min')
                    ->label('Temp. MÃ­n')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('temp_max')
                    ->label('Temp. MÃ¡x')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('volume')
                    ->label('Volume (mÂ³)')
                    ->numeric()
                    ->sortable()
                    ->placeholder('â€”'),
                Tables\Columns\IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
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
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPools::route('/'),
            'create' => Pages\CreatePool::route('/create'),
            'view' => Pages\ViewPool::route('/{record}'),
            'edit' => Pages\EditPool::route('/{record}/edit'),
        ];
    }
}

