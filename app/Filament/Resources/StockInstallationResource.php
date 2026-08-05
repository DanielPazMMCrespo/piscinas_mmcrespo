<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\PaginaGestor;
use App\Filament\Resources\StockInstallationResource\Pages;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Services\StockService;
use DomainException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * [AI_CONTEXT]
 *
 * IDEALIZADO:
 * Gestão do stock local de cada instalação. Os técnicos gastam este stock ao registarem
 * adições de químicos nos Registos Diários.
 *
 * IMPLEMENTADO:
 * - Validação de Quantidade: Ao registar um consumo (no Registo Diário ou manualmente),
 *   o sistema garante que a instalação tem quantidade disponível suficiente.
 * - Regra Estrita de DB: Tal como no armazém, movimentações obrigam a `DB::transaction()` e `lockForUpdate()`.
 * - Rastreabilidade: Criação de `StockInstallationLog` automático para entradas e consumos.
 *
 * EM FALTA (ROADMAP):
 * - N/A
 */
class StockInstallationResource extends Resource
{
    protected static ?string $model = StockInstallation::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?string $modelLabel = 'Stock na Instalação';

    protected static ?string $pluralModelLabel = 'Stock nas Instalações';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['admin', 'tecnico', 'gestor'])
            && $user->podeVerPagina(PaginaGestor::STOCK_INSTALACAO);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['produto.name', 'produto.categoria', 'instalacao.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return ($record->produto?->name ?? 'Produto').' — '.($record->instalacao?->name ?? '—');
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Disponível' => number_format((float) $record->quantity, 3, ',', ' ').' '.($record->produto?->unidade ?? ''),
            'Mínimo' => number_format((float) $record->limite_minimo, 3, ',', ' ').' '.($record->produto?->unidade ?? ''),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['produto', 'instalacao']);
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
                Forms\Components\Select::make('product_id')
                    ->label('Produto')
                    ->relationship('produto', 'name')
                    ->required()
                    ->preload()
                    ->searchable()
                    ->rule(fn (Forms\Get $get, ?StockInstallation $record): Unique => Rule::unique('stock_installations', 'product_id')
                        ->where('installation_id', $get('installation_id'))
                        ->ignore($record?->id))
                    ->validationMessages([
                        'unique' => 'Este produto já tem stock registado nesta instalação. Edite o registo existente.',
                    ]),
                Forms\Components\TextInput::make('quantity')
                    ->label('Quantidade em Stock')
                    ->helperText('Quantidade já existente na instalação (pode ser 0).')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required(),
                Forms\Components\TextInput::make('limite_minimo')
                    ->label('Alerta de Stock Baixo (Mínimo)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\TextEntry::make('produto.name')
                        ->label('Produto')
                        ->icon('heroicon-o-beaker')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\TextEntry::make('instalacao.name')
                        ->label('Instalação')
                        ->icon('heroicon-o-building-office-2'),
                    Infolists\Components\TextEntry::make('quantity')
                        ->label('Quantidade atual')
                        ->badge()
                        ->color(fn (StockInstallation $record): string => (float) $record->quantity <= (float) $record->limite_minimo ? 'danger' : 'success')
                        ->formatStateUsing(fn ($state, StockInstallation $record): string => number_format((float) $state, 3, ',', ' ').' '.($record->produto?->unidade ?? '')),
                    Infolists\Components\TextEntry::make('limite_minimo')
                        ->label('Alerta de stock baixo')
                        ->formatStateUsing(fn ($state, StockInstallation $record): string => number_format((float) $state, 3, ',', ' ').' '.($record->produto?->unidade ?? '')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['instalacao', 'produto']))
            ->columns([
                Tables\Columns\TextColumn::make('instalacao.name')
                    ->label('Instalação')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('produto.name')
                    ->label('Produto')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade Atual')
                    ->formatStateUsing(fn ($state, StockInstallation $record): string => number_format((float) $state, 3, ',', ' ').' '.($record->produto?->unidade ?? ''))
                    ->description(fn (StockInstallation $record): string => 'mín. '.number_format((float) $record->limite_minimo, 3, ',', ' '))
                    ->badge()
                    ->color(fn (StockInstallation $record): string => (float) $record->quantity <= (float) $record->limite_minimo ? 'danger' : 'gray')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('abaixo_minimo')
                    ->label('Só abaixo do mínimo')
                    ->query(fn (Builder $query): Builder => $query->whereColumn('quantity', '<=', 'limite_minimo'))
                    ->toggle(),
                Tables\Filters\SelectFilter::make('installation_id')
                    ->label('Instalação')
                    ->relationship('instalacao', 'name')
                    ->preload(),
                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Produto')
                    ->relationship('produto', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('entrada_stock')
                    ->label('Entrada Direta')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn ($record) => auth()->user()->can('update', $record))
                    ->modalHeading(fn (StockInstallation $record): string => 'Entrada de '.($record->produto?->name ?? 'produto'))
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade recebida')
                            ->suffix(fn (StockInstallation $record) => $record->produto?->unidade)
                            ->helperText('Entrega direta na instalação (fora do fluxo do armazém central).')
                            ->numeric()
                            ->minValue(0.001)
                            ->rules(['gt:0'])
                            ->required(),
                    ])
                    ->action(function (StockInstallation $record, array $data, Tables\Actions\Action $action): void {
                        try {
                            app(StockService::class)->addInstallationStock(
                                $record->id,
                                (float) $data['quantidade'],
                                auth()->id()
                            );

                            Notification::make()
                                ->success()
                                ->title('Entrada registada')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Não foi possível registar a entrada')
                                ->body($e->getMessage())
                                ->send();

                            $action->halt();
                        }
                    }),
                Tables\Actions\Action::make('consumo_stock')
                    ->label('Consumo Manual')
                    ->icon('heroicon-o-beaker')
                    ->color('warning')
                    ->visible(fn ($record) => auth()->user()->can('update', $record))
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade consumida (Ajuste Manual)')
                            ->suffix(fn (StockInstallation $record) => $record->produto?->unidade)
                            ->helperText(fn (StockInstallation $record): string => 'Disponível: '.number_format((float) $record->quantity, 3, ',', ' ').' '.($record->produto?->unidade ?? ''))
                            ->numeric()
                            ->minValue(0.001)
                            ->maxValue(fn (StockInstallation $record) => (float) $record->quantity)
                            ->rules(['gt:0'])
                            ->required(),
                    ])
                    ->modalHeading(fn (StockInstallation $record): string => 'Consumo de '.($record->produto?->name ?? 'produto'))
                    ->action(function (StockInstallation $record, array $data, Tables\Actions\Action $action): void {
                        try {
                            app(StockService::class)->consumeInstallationStock(
                                $record->id,
                                (float) $data['quantidade'],
                                auth()->id()
                            );

                            Notification::make()
                                ->success()
                                ->title('Consumo registado')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Stock insuficiente')
                                ->body($e->getMessage())
                                ->send();

                            $action->halt();
                        }
                    }),
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
            'index' => Pages\ListStockInstallations::route('/'),
            'create' => Pages\CreateStockInstallation::route('/create'),
            'view' => Pages\ViewStockInstallation::route('/{record}'),
            'edit' => Pages\EditStockInstallation::route('/{record}/edit'),
        ];
    }
}
