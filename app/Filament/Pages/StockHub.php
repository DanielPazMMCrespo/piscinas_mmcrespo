<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\Installation;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
use App\Services\StockService;
use DomainException;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Uma linha por produto: armazém + cada instalação + ação inline, para
 * responder "tenho cloro?" sem passar por 6 páginas diferentes e sem somar
 * unidades diferentes de cabeça (a auditoria mediu 6 toques + soma mental
 * para responder a essa pergunta hoje).
 */
class StockHub extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Visão Geral';

    protected static ?string $title = 'Stock — Visão Geral';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.stock-hub';

    protected static ?string $slug = 'stock';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::GESTOR]) ?? false;
    }

    private static function podeMovimentar(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false;
    }

    /** @return array<int, Installation> */
    private function instalacoes(): array
    {
        return Installation::query()->where('active', true)->orderBy('name')->get()->all();
    }

    public function table(Table $table): Table
    {
        $instalacoes = $this->instalacoes();

        $colunas = [
            Tables\Columns\TextColumn::make('name')
                ->label('Produto')
                ->weight('bold')
                ->searchable()
                ->description(fn (Product $r): ?string => $r->categoria),
            Tables\Columns\TextColumn::make('unidade')
                ->label('Unid.')
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('armazem')
                ->label('Armazém')
                ->state(fn (Product $r): string => number_format((float) ($r->stockArmazem?->quantity ?? 0), 3, ',', ' ').' '.$r->unidade)
                ->badge()
                ->color('gray')
                ->action(self::armazemAction()),
        ];

        foreach ($instalacoes as $instalacao) {
            $colunas[] = Tables\Columns\TextColumn::make("inst_{$instalacao->id}")
                ->label($instalacao->name)
                ->state(function (Product $r) use ($instalacao): string {
                    $stock = $r->stockInstalacoes->firstWhere('installation_id', $instalacao->id);
                    if ($stock === null) {
                        return '—';
                    }

                    return number_format((float) $stock->quantity, 3, ',', ' ').' '.$r->unidade;
                })
                ->badge()
                ->color(function (Product $r) use ($instalacao): string {
                    $stock = $r->stockInstalacoes->firstWhere('installation_id', $instalacao->id);
                    if ($stock === null) {
                        return 'gray';
                    }

                    return (float) $stock->quantity <= (float) $stock->limite_minimo ? 'danger' : 'gray';
                })
                ->action(self::instalacaoAction($instalacao));
        }

        return $table
            ->query(Product::query()->where('active', true)->with(['stockArmazem', 'stockInstalacoes']))
            ->columns($colunas)
            ->filters([
                Tables\Filters\Filter::make('abaixo_minimo')
                    ->label('Só produtos abaixo do mínimo nalguma instalação')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'stockInstalacoes',
                        fn (Builder $q) => $q->whereColumn('quantity', '<=', 'limite_minimo')
                    )),
                Tables\Filters\SelectFilter::make('categoria')
                    ->label('Categoria')
                    ->options(fn (): array => Product::query()
                        ->whereNotNull('categoria')
                        ->distinct()
                        ->orderBy('categoria')
                        ->pluck('categoria', 'categoria')
                        ->all()),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Sem produtos ativos')
            ->emptyStateDescription('Os produtos configuram-se em Stock › Produtos Químicos.')
            ->paginated(false);
    }

    /**
     * Uma única ação na coluna Armazém: dar entrada (fornecedor) ou transferir
     * para uma instalação. Evita ter duas colunas de ação separadas por produto.
     */
    private static function armazemAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('armazem_acao')
            ->label('Movimentar')
            ->icon('heroicon-o-archive-box')
            ->visible(fn (): bool => self::podeMovimentar())
            ->modalHeading(fn (Product $record): string => 'Armazém — '.$record->name)
            ->form([
                Forms\Components\Radio::make('tipo')
                    ->label('Operação')
                    ->options([
                        'entrada' => 'Entrada (fornecedor)',
                        'transferir' => 'Transferir para instalação',
                    ])
                    ->default('entrada')
                    ->live()
                    ->required(),
                Forms\Components\Select::make('installation_id')
                    ->label('Instalação de destino')
                    ->options(fn (): array => Installation::query()->where('active', true)->pluck('name', 'id')->all())
                    ->visible(fn (Get $get): bool => $get('tipo') === 'transferir')
                    ->required(fn (Get $get): bool => $get('tipo') === 'transferir')
                    ->searchable(),
                Forms\Components\TextInput::make('quantidade')
                    ->label('Quantidade')
                    ->suffix(fn (Product $record) => $record->unidade)
                    ->helperText(fn (Product $record): string => 'Em armazém: '
                        .number_format((float) ($record->stockArmazem?->quantity ?? 0), 3, ',', ' ').' '.$record->unidade)
                    ->numeric()
                    ->minValue(0.001)
                    ->maxValue(fn (Get $get, Product $record) => $get('tipo') === 'transferir'
                        ? (float) ($record->stockArmazem?->quantity ?? 0)
                        : null)
                    ->rules(['gt:0'])
                    ->required(),
                Forms\Components\Textarea::make('observacoes')
                    ->label('Observações (ex.: nº da fatura/guia)')
                    ->maxLength(255),
            ])
            ->action(function (Product $record, array $data, Tables\Actions\Action $action): void {
                $armazem = StockWarehouse::firstOrCreate(
                    ['product_id' => $record->id],
                    ['quantity' => 0]
                );

                try {
                    if ($data['tipo'] === 'transferir') {
                        app(StockService::class)->transferToInstallation(
                            $armazem->id,
                            $data['installation_id'],
                            (float) $data['quantidade'],
                            auth()->id(),
                            $data['observacoes'] ?? null
                        );
                        $titulo = 'Transferência concluída';
                    } else {
                        app(StockService::class)->addWarehouseStock(
                            $armazem->id,
                            (float) $data['quantidade'],
                            auth()->id(),
                            $data['observacoes'] ?? null
                        );
                        $titulo = 'Entrada registada';
                    }

                    Notification::make()->success()->title($titulo)->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('Não foi possível concluir')->body($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    /**
     * Ação por instalação: entrada direta (fornecedor entrega no local) ou
     * consumo manual (ajuste). Cria a linha de stock se ainda não existir.
     */
    private static function instalacaoAction(Installation $instalacao): Tables\Actions\Action
    {
        return Tables\Actions\Action::make("inst_acao_{$instalacao->id}")
            ->label('Movimentar')
            ->icon('heroicon-o-building-storefront')
            ->visible(fn (): bool => self::podeMovimentar())
            ->modalHeading(fn (Product $record): string => $instalacao->name.' — '.$record->name)
            ->form(function (Product $record) use ($instalacao): array {
                $stock = $record->stockInstalacoes->firstWhere('installation_id', $instalacao->id);
                $disponivel = (float) ($stock?->quantity ?? 0);

                return [
                    Forms\Components\Radio::make('tipo')
                        ->label('Operação')
                        ->options([
                            'entrada' => 'Entrada direta (fornecedor entrega no local)',
                            'consumo' => 'Consumo manual (ajuste)',
                        ])
                        ->default('entrada')
                        ->live()
                        ->required(),
                    Forms\Components\TextInput::make('quantidade')
                        ->label('Quantidade')
                        ->suffix($record->unidade)
                        ->helperText("Disponível: {$disponivel} {$record->unidade}")
                        ->numeric()
                        ->minValue(0.001)
                        ->maxValue(fn (Get $get) => $get('tipo') === 'consumo' ? $disponivel : null)
                        ->rules(['gt:0'])
                        ->required(),
                ];
            })
            ->action(function (Product $record, array $data, Tables\Actions\Action $action) use ($instalacao): void {
                $stock = StockInstallation::firstOrCreate(
                    ['installation_id' => $instalacao->id, 'product_id' => $record->id],
                    ['quantity' => 0, 'limite_minimo' => 0]
                );

                try {
                    if ($data['tipo'] === 'consumo') {
                        app(StockService::class)->consumeInstallationStock($stock->id, (float) $data['quantidade'], auth()->id());
                        $titulo = 'Consumo registado';
                    } else {
                        app(StockService::class)->addInstallationStock($stock->id, (float) $data['quantidade'], auth()->id());
                        $titulo = 'Entrada registada';
                    }

                    Notification::make()->success()->title($titulo)->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('Não foi possível concluir')->body($e->getMessage())->send();
                    $action->halt();
                }
            });
    }
}
