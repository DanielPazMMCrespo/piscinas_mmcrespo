<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PoolResource\Pages;
use App\Models\Pool;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PoolResource extends Resource
{
    protected static ?string $model = Pool::class;

    protected static ?string $navigationIcon = 'heroicon-o-view-columns';

    protected static ?string $navigationGroup = 'Estrutura';

    protected static ?string $modelLabel = 'Piscina';

    protected static ?string $pluralModelLabel = 'Piscinas';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'instalacao.name'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return ['Instalação' => $record->instalacao->name];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('instalacao');
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
                Forms\Components\TextInput::make('name')
                    ->label('Nome da Piscina')
                    ->required()
                    ->maxLength(100),
                Forms\Components\TextInput::make('type')
                    ->label('Tipo (ex: Interior, Exterior, Infantil)')
                    ->required()
                    ->maxLength(50),
                Forms\Components\TextInput::make('temp_min')
                    ->label('Temperatura Mínima (ºC)')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('temp_max')
                    ->label('Temperatura Máxima (ºC)')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('orp_min')
                    ->label('ORP Mínimo (mV)')
                    ->numeric()
                    ->step(1)
                    ->placeholder('660')
                    ->helperText('Opcional. Valor por defeito: 660 mV.'),
                Forms\Components\TextInput::make('orp_max')
                    ->label('ORP Máximo (mV)')
                    ->numeric()
                    ->step(1)
                    ->placeholder('750')
                    ->helperText('Opcional. Valor por defeito: 750 mV.'),
                Forms\Components\TextInput::make('volume')
                    ->label('Volume (m³)')
                    ->helperText('Necessário para a calculadora de dosagem de químicos.')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0)
                    ->suffix('m³'),
                Forms\Components\Toggle::make('active')
                    ->label('Ativo')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label('Piscina')
                        ->icon('heroicon-o-view-columns')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\IconEntry::make('active')
                        ->label('Ativo')
                        ->boolean(),
                    Infolists\Components\TextEntry::make('instalacao.name')
                        ->label('Instalação')
                        ->icon('heroicon-o-building-office-2'),
                    Infolists\Components\TextEntry::make('type')
                        ->label('Tipo')
                        ->badge(),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Parâmetros')
                ->icon('heroicon-o-adjustments-horizontal')
                ->schema([
                    Infolists\Components\TextEntry::make('volume')
                        ->label('Volume')
                        ->icon('heroicon-o-cube')
                        ->suffix(' m³')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('temp_min')
                        ->label('Temp. mínima')
                        ->icon('heroicon-o-fire')
                        ->suffix(' ºC'),
                    Infolists\Components\TextEntry::make('temp_max')
                        ->label('Temp. máxima')
                        ->icon('heroicon-o-fire')
                        ->suffix(' ºC'),
                    Infolists\Components\TextEntry::make('orp_min')
                        ->label('ORP mínimo')
                        ->suffix(' mV')
                        ->placeholder('660 (padrão)'),
                    Infolists\Components\TextEntry::make('orp_max')
                        ->label('ORP máximo')
                        ->suffix(' mV')
                        ->placeholder('750 (padrão)'),
                ])
                ->columns(3),

            Infolists\Components\Section::make('Registo')
                ->icon('heroicon-o-clock')
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Criado em')
                        ->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Atualizado em')
                        ->dateTime('d/m/Y H:i'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('encerramentos'))
            ->columns([
                Tables\Columns\TextColumn::make('instalacao.name')
                    ->label('Instalação')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Piscina')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->searchable(),
                Tables\Columns\TextColumn::make('temp_min')
                    ->label('Temp. Mín')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('temp_max')
                    ->label('Temp. Máx')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('volume')
                    ->label('Volume (m³)')
                    ->numeric()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('active')
                    ->label('Ativo')
                    ->boolean(),
                Tables\Columns\TextColumn::make('estado_operacional')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Pool::ESTADO_ENCERRADA => 'Encerrada',
                        Pool::ESTADO_DESATIVADA => 'Desativada',
                        default => 'Aberta',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Pool::ESTADO_ENCERRADA => 'warning',
                        Pool::ESTADO_DESATIVADA => 'gray',
                        default => 'success',
                    })
                    ->description(fn (Pool $record): ?string => $record->encerramentoEm()?->motivo_label)
                    // 'active = false' sem encerramento datado é um estado legado:
                    // esconde a piscina de tudo, incluindo do passado, o que o
                    // livro sanitário não pode ter.
                    ->tooltip(fn (Pool $record): ?string => ! $record->active && ! $record->estaEncerradaEm()
                        ? 'Desativada sem período datado. Para um fecho temporário, use Operação → Encerramentos: mantém o histórico e justifica os dias sem registos no livro sanitário.'
                        : null),
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
                Tables\Actions\ViewAction::make()->slideOver(),
                Tables\Actions\EditAction::make()->slideOver(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalDescription('Eliminar uma piscina apaga em cascata os seus registos diários, verificações de filtro, ações operacionais, leituras de sensores e bidões de dosagem. Esta ação é irreversível.'),
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
