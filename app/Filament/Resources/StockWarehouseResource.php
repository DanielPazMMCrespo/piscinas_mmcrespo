<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\PaginaGestor;
use App\Constants\UserRole;
use App\Filament\Resources\StockWarehouseResource\Pages;
use App\Models\Installation;
use App\Models\StockWarehouse;
use App\Models\StockWarehouseLog;
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

/**
 * [AI_CONTEXT]
 *
 * IDEALIZADO:
 * Gestão do stock central (armazém) da MMCrespo. Daqui, os químicos são distribuídos
 * para as várias instalações.
 *
 * IMPLEMENTADO:
 * - Ação "Transferir p/ Instalação": Única forma de dar saída de stock. Debita do armazém e credita na instalação.
 * - Regra Estrita de DB: Todas as movimentações de stock OBRIGAM ao uso de `DB::transaction()`
 *   em conjunto com `lockForUpdate()` para prevenir race conditions em concorrência.
 * - Rastreabilidade: Criação de logs automáticos (`StockWarehouseLog`) em cada movimentação.
 *
 * EM FALTA (ROADMAP):
 * - N/A
 */
class StockWarehouseResource extends Resource
{
    protected static ?string $model = StockWarehouse::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?string $modelLabel = 'Stock de Armazém';

    protected static ?string $pluralModelLabel = 'Stock de Armazém';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole(['admin', 'tecnico', 'gestor'])
            && $user->podeVerPagina(PaginaGestor::STOCK_ARMAZEM);
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['produto.name', 'produto.categoria'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->produto?->name ?? 'Produto';
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Categoria' => $record->produto?->categoria ?? '—',
            'Em armazém' => number_format((float) $record->quantity, 3, ',', ' ').' '.($record->produto?->unidade ?? ''),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('produto');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Produto')
                    ->relationship('produto', 'name')
                    ->required()
                    ->preload()
                    ->searchable()
                    ->unique(ignoreRecord: true)
                    // Criação rápida sem sair do Armazém; edição de categoria/concentração/
                    // desativação continua só em Stock > Produtos Químicos.
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome do Produto')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\TextInput::make('unidade')
                            ->label('Unidade de Medida (ex: kg, L)')
                            ->required()
                            ->maxLength(255),
                    ]),
                Forms\Components\TextInput::make('quantity')
                    ->label('Quantidade Inicial em Stock')
                    ->required()
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
                    Infolists\Components\TextEntry::make('produto.categoria')
                        ->label('Categoria')
                        ->badge()
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('quantity')
                        ->label('Quantidade em armazém')
                        ->badge()
                        ->color('info')
                        ->formatStateUsing(fn ($state, StockWarehouse $record): string => number_format((float) $state, 3, ',', ' ').' '.($record->produto?->unidade ?? '')),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->modifyQueryUsing(fn ($query) => $query->with('produto'))
            ->columns([
                Tables\Columns\TextColumn::make('produto.name')
                    ->label('Produto')
                    ->description(fn (StockWarehouse $record): ?string => $record->produto?->categoria)
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Quantidade em Stock')
                    // Sem unidade, "2,000" não diz se são 2 kg ou 2 L.
                    ->formatStateUsing(fn ($state, StockWarehouse $record): string => number_format((float) $state, 3, ',', ' ').' '.($record->produto?->unidade ?? ''))
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Produto')
                    ->relationship('produto', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()->hasRole(UserRole::ADMIN)),
                Tables\Actions\Action::make('entrada_stock')
                    ->label('Entrada')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->authorize(fn ($record) => auth()->user()->can('updateStock', $record))
                    ->visible(fn ($record) => auth()->user()->can('updateStock', $record))
                    ->modalHeading(fn (StockWarehouse $record): string => 'Entrada de '.($record->produto?->name ?? 'produto'))
                    ->form([
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade a adicionar')
                            ->suffix(fn (StockWarehouse $record) => $record->produto?->unidade)
                            ->helperText(fn (StockWarehouse $record): string => 'Em armazém: '.number_format((float) $record->quantity, 3, ',', ' ').' '.($record->produto?->unidade ?? ''))
                            ->numeric()
                            ->minValue(0.001)
                            ->rules(['gt:0'])
                            ->required(),
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações (ex: Nº da Fatura)')
                            ->maxLength(255),
                    ])
                    ->action(function (StockWarehouse $record, array $data): void {
                        app(StockService::class)->addWarehouseStock(
                            $record->id,
                            (float) $data['quantidade'],
                            auth()->id(),
                            $data['observacoes'] ?? null
                        );

                        Notification::make()
                            ->success()
                            ->title('Entrada registada')
                            ->send();
                    }),
                Tables\Actions\Action::make('transferir_instalacao')
                    ->label('Transferir p/ Instalação')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('primary')
                    ->authorize(fn ($record) => auth()->user()->can('transferStock', $record))
                    ->visible(fn ($record) => auth()->user()->can('transferStock', $record))
                    // Em telemóvel o modal cobre a linha da tabela: sem o nome do
                    // produto no título não há como confirmar em que linha se tocou.
                    ->modalHeading(fn (StockWarehouse $record): string => 'Transferir '.($record->produto?->name ?? 'produto'))
                    ->form([
                        Forms\Components\Select::make('installation_id')
                            ->label('Instalação de Destino')
                            ->options(Installation::where('active', true)->pluck('name', 'id'))
                            ->required()
                            ->searchable(),
                        Forms\Components\TextInput::make('quantidade')
                            ->label('Quantidade a transferir')
                            ->suffix(fn (StockWarehouse $record) => $record->produto?->unidade)
                            ->helperText(fn (StockWarehouse $record): string => 'Disponível em armazém: '.number_format((float) $record->quantity, 3, ',', ' ').' '.($record->produto?->unidade ?? ''))
                            ->numeric()
                            ->minValue(0.001)
                            // Valida antes de submeter, em vez de falhar e fechar o modal.
                            ->maxValue(fn (StockWarehouse $record) => (float) $record->quantity)
                            ->rules(['gt:0'])
                            ->required(),
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações (ex: Nº da Guia)')
                            ->maxLength(255),
                    ])
                    ->action(function (StockWarehouse $record, array $data, Tables\Actions\Action $action): void {
                        try {
                            app(StockService::class)->transferToInstallation(
                                $record->id,
                                $data['installation_id'],
                                (float) $data['quantidade'],
                                auth()->id(),
                                $data['observacoes'] ?? null
                            );

                            Notification::make()
                                ->success()
                                ->title('Transferência concluída')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Erro na transferência')
                                ->body($e->getMessage())
                                ->send();

                            // halt() mantém o modal aberto com os dados escritos.
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
            'index' => Pages\ListStockWarehouses::route('/'),
            'create' => Pages\CreateStockWarehouse::route('/create'),
            'view' => Pages\ViewStockWarehouse::route('/{record}'),
            'edit' => Pages\EditStockWarehouse::route('/{record}/edit'),
        ];
    }
}
