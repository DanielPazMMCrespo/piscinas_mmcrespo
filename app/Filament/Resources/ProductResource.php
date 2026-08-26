<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\PaginaGestor;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Stock';

    /** Sem isto o título do resultado na pesquisa global era o nome do modelo, não o registo. */
    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Produto Químico';

    protected static ?string $pluralModelLabel = 'Produtos Químicos';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['admin', 'tecnico', 'gestor'])
            && $user->podeVerPagina(PaginaGestor::PRODUTOS);
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'categoria'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Categoria' => $record->categoria ?? '—',
            'Unidade' => $record->unidade,
        ];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->label('Nome do Produto')
                    ->required()
                    ->maxLength(100),
                Select::make('unidade')
                    ->label('Unidade de Medida')
                    ->options([
                        'L' => 'L',
                        'kg' => 'kg',
                        'un' => 'un',
                    ])
                    ->required(),
                Select::make('categoria')
                    ->label('Categoria')
                    ->options(function (): array {
                        $categorias = Product::query()
                            ->distinct()
                            ->whereNotNull('categoria')
                            ->pluck('categoria')
                            ->sort()
                            ->mapWithKeys(fn ($cat) => [$cat => $cat])
                            ->all();
                        $categorias['outro'] = 'Outro';

                        return $categorias;
                    })
                    ->live(),
                TextInput::make('categoria_custom')
                    ->label('Especificar Categoria')
                    ->maxLength(50)
                    ->visible(fn (Get $get) => $get('categoria') === 'outro'),
                TextInput::make('concentracao_cl')
                    ->label('Concentração de cloro ativo (%)')
                    ->helperText('Ex: 56 para granulado, 16,8 para hipoclorito de sódio. Usado na calculadora de dosagem.')
                    ->numeric()
                    ->step(0.01)
                    ->minValue(0.01)
                    ->maxValue(100)
                    ->suffix('%')
                    ->nullable(),
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
                        ->label('Produto')
                        ->icon('heroicon-o-beaker')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\IconEntry::make('active')
                        ->label('Ativo')
                        ->boolean(),
                    Infolists\Components\TextEntry::make('categoria')
                        ->label('Categoria')
                        ->badge()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('unidade')
                        ->label('Unidade de medida'),
                    Infolists\Components\TextEntry::make('concentracao_cl')
                        ->label('Concentração de cloro ativo')
                        ->suffix('%')
                        ->placeholder('—'),
                ])
                ->columns(2),

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
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Produto')
                    ->searchable(),
                Tables\Columns\TextColumn::make('unidade')
                    ->label('Unidade')
                    ->searchable(),
                Tables\Columns\TextColumn::make('categoria')
                    ->label('Categoria')
                    ->searchable(),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'view' => Pages\ViewProduct::route('/{record}'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
