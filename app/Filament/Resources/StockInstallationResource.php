<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\StockInstallationResource\Pages;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Services\StockService;
use DomainException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
        return auth()->user()->hasAnyRole(['admin', 'tecnico']);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
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
                Forms\Components\TextInput::make('limite_minimo')
                    ->label('Alerta de Stock Baixo (Mínimo)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->numeric(3)
                    ->sortable(),
                Tables\Columns\TextColumn::make('limite_minimo')
                    ->label('Alerta Mín.')
                    ->numeric(3)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('entrada_stock')
                    ->label('Entrada Direta')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->visible(fn ($record) => auth()->user()->can('update', $record))
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade recebida')
                            ->helperText('Entrega direta na instalação (fora do fluxo do armazém central).')
                            ->numeric()
                            ->minValue(0.001)
                            ->rules(['gt:0'])
                            ->required(),
                    ])
                    ->action(function (StockInstallation $record, array $data): void {
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
                            ->numeric()
                            ->minValue(0.001)
                            ->rules(['gt:0'])
                            ->required(),
                    ])
                    ->action(function (StockInstallation $record, array $data): void {
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
