<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\PaginaGestor;
use App\Constants\UserRole;
use App\Filament\Resources\ProductResource;
use App\Models\DosingContainer;
use App\Models\Installation;
use App\Models\Product;
use App\Models\StockInstallation;
use App\Models\StockWarehouse;
use App\Models\StockWarehouseLog;
use App\Services\StockService;
use DomainException;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Components\Tab;
use Filament\Resources\Concerns\HasTabs;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Gestão Unificada de Stock & Químicos (Apple HIG & Tesla Field UX):
 * - Resumo executivo de KPIs (Alertas de ruptura, Armazém Geral, Bidões de Dosagem)
 * - Navegação segmentada sem recarga
 * - Visualização e reposição direta de bidões das salas de máquinas (DosingContainers)
 * - Ações expressas com botões táteis (≥ 44px) e presets de bidões habituais
 */
class StockHub extends Page implements HasForms, HasTable
{
    use HasTabs;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Gestão';

    protected static ?string $navigationLabel = 'Stock';

    protected static ?string $title = 'Gestão de Stock';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.stock-hub';

    protected static ?string $slug = 'stock';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::GESTOR])
            && $user->podeVerPagina(PaginaGestor::STOCK_VISAO_GERAL);
    }

    private static function podeMovimentar(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('criarProduto')
                ->label('Novo Produto')
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->visible(fn (): bool => ProductResource::canAccess())
                ->slideOver()
                ->modalHeading('Adicionar Novo Produto Químico')
                ->form([
                    Forms\Components\TextInput::make('name')
                        ->label('Nome do Produto')
                        ->required()
                        ->maxLength(100),
                    Forms\Components\Select::make('unidade')
                        ->label('Unidade de Medida')
                        ->options([
                            'L' => 'L (Litros)',
                            'kg' => 'kg (Quilogramas)',
                            'un' => 'un (Unidades)',
                        ])
                        ->required(),
                    Forms\Components\Select::make('categoria')
                        ->label('Categoria')
                        ->options(function (): array {
                            return Product::query()
                                ->distinct()
                                ->whereNotNull('categoria')
                                ->pluck('categoria', 'categoria')
                                ->all();
                        })
                        ->searchable(),
                    Forms\Components\TextInput::make('concentracao_cl')
                        ->label('Concentração de cloro ativo (%)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0.01)
                        ->maxValue(100)
                        ->suffix('%')
                        ->extraInputAttributes(['pattern' => '[0-9.,]*', 'inputmode' => 'decimal'])
                        ->helperText('Deixe vazio se não se aplica. Zero não é aceite: impediria o cálculo da dose.')
                        ->nullable(),
                ])
                ->action(function (array $data): void {
                    Product::create([
                        'name' => $data['name'],
                        'unidade' => $data['unidade'],
                        'categoria' => $data['categoria'] ?? null,
                        'concentracao_cl' => $data['concentracao_cl'] ?? null,
                        'active' => true,
                    ]);

                    Notification::make()->success()->title('Produto adicionado ao catálogo')->send();
                }),
        ];
    }

    protected function getActions(): array
    {
        return [
            ...$this->getHeaderActions(),
            $this->reabastecerBidaoAction(),
        ];
    }

    public function getTabs(): array
    {
        $criticosCount = Product::query()
            ->where('active', true)
            ->whereHas('stockInstalacoes', fn (Builder $q) => $q->whereColumn('quantity', '<=', 'limite_minimo'))
            ->count();

        return [
            'todos' => Tab::make('Todos os Produtos'),
            'abaixo_minimo' => Tab::make('Abaixo do Mínimo')
                ->badge($criticosCount > 0 ? (string) $criticosCount : null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'stockInstalacoes',
                    fn (Builder $q) => $q->whereColumn('quantity', '<=', 'limite_minimo')
                )),
        ];
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
            Tables\Columns\TextColumn::make('armazem')
                ->label('Armazém')
                ->state(fn (Product $r): string => number_format((float) ($r->stockArmazem?->quantity ?? 0), 1, ',', ' ').' '.$r->unidade)
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

                    return number_format((float) $stock->quantity, 1, ',', ' ').' '.$r->unidade;
                })
                ->badge()
                ->color(function (Product $r) use ($instalacao): string {
                    $stock = $r->stockInstalacoes->firstWhere('installation_id', $instalacao->id);
                    if ($stock === null) {
                        return 'gray';
                    }

                    return (float) $stock->quantity <= (float) $stock->limite_minimo ? 'danger' : 'success';
                })
                ->action(self::instalacaoAction($instalacao));
        }

        return $table
            ->query(Product::query()->where('active', true)->with(['stockArmazem', 'stockInstalacoes']))
            ->columns($colunas)
            ->filters([
                Tables\Filters\Filter::make('abaixo_minimo')
                    ->label('Só produtos abaixo do mínimo')
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
            ->emptyStateDescription('Os produtos configuram-se através do botão Novo Produto.')
            ->paginated(false);
    }

    /**
     * Ação de movimentação no Armazém: entrada (receção de fornecedor) ou transferência
     * direta para uma piscina, com presets rápidos de 1 toque (25L / 50L / 100L).
     */
    private static function armazemAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('armazem_acao')
            ->label('Movimentar')
            ->icon('heroicon-o-archive-box')
            ->visible(fn (): bool => self::podeMovimentar())
            ->modalHeading(fn (Product $record): string => 'Armazém Central — '.$record->name)
            ->form([
                Forms\Components\ToggleButtons::make('tipo')
                    ->label('Operação')
                    ->options([
                        'entrada' => 'Entrada (Fornecedor)',
                        'transferir' => 'Transferir para Piscina',
                    ])
                    ->icons([
                        'entrada' => 'heroicon-o-arrow-down-tray',
                        'transferir' => 'heroicon-o-truck',
                    ])
                    ->colors([
                        'entrada' => 'success',
                        'transferir' => 'primary',
                    ])
                    ->default('entrada')
                    ->live()
                    ->inline()
                    ->required(),
                Forms\Components\Select::make('installation_id')
                    ->label('Instalação de Destino')
                    ->options(fn (): array => Installation::query()->where('active', true)->pluck('name', 'id')->all())
                    ->visible(fn (Get $get): bool => $get('tipo') === 'transferir')
                    ->required(fn (Get $get): bool => $get('tipo') === 'transferir')
                    ->searchable(),
                Forms\Components\TextInput::make('quantidade')
                    ->label('Quantidade')
                    ->suffix(fn (Product $record) => $record->unidade)
                    ->helperText(fn (Product $record): string => 'Disponível em armazém: '
                        .number_format((float) ($record->stockArmazem?->quantity ?? 0), 1, ',', ' ').' '.$record->unidade)
                    ->numeric()
                    ->minValue(0.001)
                    ->maxValue(fn (Get $get, Product $record) => $get('tipo') === 'transferir'
                        ? (float) ($record->stockArmazem?->quantity ?? 0)
                        : null)
                    ->rules(['gt:0'])
                    ->extraInputAttributes(['pattern' => '[0-9.,]*', 'inputmode' => 'decimal'])
                    ->hintActions([
                        Forms\Components\Actions\Action::make('add10')
                            ->label('+10')
                            ->action(fn (Set $set, Get $get) => $set('quantidade', (float) ($get('quantidade') ?? 0) + 10)),
                        Forms\Components\Actions\Action::make('add25')
                            ->label('+25 (1 bidão)')
                            ->action(fn (Set $set, Get $get) => $set('quantidade', (float) ($get('quantidade') ?? 0) + 25)),
                        Forms\Components\Actions\Action::make('add50')
                            ->label('+50 (2 bidões)')
                            ->action(fn (Set $set, Get $get) => $set('quantidade', (float) ($get('quantidade') ?? 0) + 50)),
                    ])
                    ->required(),
                Forms\Components\Textarea::make('observacoes')
                    ->label('Observações (ex.: nº da guia de remessa / fatura)')
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
                        $titulo = 'Entrada registada em armazém';
                    }

                    Notification::make()->success()->title($titulo)->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('Não foi possível concluir')->body($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    /**
     * Ação por instalação: entrada direta no local ou consumo manual na sala de máquinas.
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
                    Forms\Components\ToggleButtons::make('tipo')
                        ->label('Operação')
                        ->options([
                            'entrada' => 'Entrada Direta no Local',
                            'consumo' => 'Registar Consumo Manual',
                        ])
                        ->icons([
                            'entrada' => 'heroicon-o-arrow-down-tray',
                            'consumo' => 'heroicon-o-beaker',
                        ])
                        ->colors([
                            'entrada' => 'success',
                            'consumo' => 'warning',
                        ])
                        ->default('entrada')
                        ->live()
                        ->inline()
                        ->required(),
                    Forms\Components\TextInput::make('quantidade')
                        ->label('Quantidade')
                        ->suffix($record->unidade)
                        ->helperText("Stock local disponível: {$disponivel} {$record->unidade}")
                        ->numeric()
                        ->minValue(0.001)
                        ->maxValue(fn (Get $get) => $get('tipo') === 'consumo' ? $disponivel : null)
                        ->rules(['gt:0'])
                        ->extraInputAttributes(['pattern' => '[0-9.,]*', 'inputmode' => 'decimal'])
                        ->hintActions([
                            Forms\Components\Actions\Action::make('add25')
                                ->label('+25 (1 bidão)')
                                ->action(fn (Set $set, Get $get) => $set('quantidade', (float) ($get('quantidade') ?? 0) + 25)),
                            Forms\Components\Actions\Action::make('add50')
                                ->label('+50 (2 bidões)')
                                ->action(fn (Set $set, Get $get) => $set('quantidade', (float) ($get('quantidade') ?? 0) + 50)),
                        ])
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
                        $titulo = 'Entrada direta registada';
                    }

                    Notification::make()->success()->title($titulo)->send();
                } catch (DomainException $e) {
                    Notification::make()->danger()->title('Não foi possível concluir')->body($e->getMessage())->send();
                    $action->halt();
                }
            });
    }

    /**
     * Ação expressa para reabastecer ou trocar o bidão ligado à bomba doseadora do controlador.
     */
    public function reabastecerBidaoAction(): Actions\Action
    {
        return Actions\Action::make('reabastecerBidao')
            ->label('Trocar / Reabastecer Bidão')
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(fn (): bool => self::podeMovimentar())
            ->modalHeading(function (array $arguments): string {
                $container = isset($arguments['container']) ? DosingContainer::find($arguments['container']) : null;

                return $container ? "Reabastecer {$container->tipoLabel()} — {$container->piscina?->name}" : 'Reabastecer Bidão de Dosagem';
            })
            ->form(function (array $arguments): array {
                $container = isset($arguments['container']) ? DosingContainer::find($arguments['container']) : null;
                $capacidadeL = $container && $container->capacidade_ml ? $container->capacidade_ml / 1000 : 25;
                $restanteL = $container && $container->restante_ml !== null ? $container->restante_ml / 1000 : 0;

                return [
                    Forms\Components\Hidden::make('container_id')
                        ->default($arguments['container'] ?? null)
                        ->required(),
                    Forms\Components\Placeholder::make('detalhes')
                        ->label('Estado do Bidão')
                        ->content("Capacidade do bidão: {$capacidadeL} L | Nível medido atual: {$restanteL} L"),
                    Forms\Components\TextInput::make('quantidade_l')
                        ->label('Nível após intervenção (Litros)')
                        ->numeric()
                        ->step('any')
                        ->minValue(0.1)
                        ->maxValue($capacidadeL * 1.2)
                        ->default($capacidadeL)
                        ->extraInputAttributes(['pattern' => '[0-9.,]*', 'inputmode' => 'decimal'])
                        ->helperText("Por defeito, enche a 100% ({$capacidadeL} L). Debita automaticamente do stock local da piscina.")
                        ->required(),
                    Forms\Components\TextInput::make('nota')
                        ->label('Nota de intervenção (opcional)')
                        ->placeholder('Ex: Substituição por bidão novo de 25L')
                        ->maxLength(255),
                ];
            })
            ->action(function (array $data): void {
                $container = DosingContainer::findOrFail($data['container_id']);
                $container->reabastecer(
                    (float) $data['quantidade_l'] * 1000,
                    auth()->id(),
                    $data['nota'] ?? null
                );

                Notification::make()
                    ->success()
                    ->title('Bidão reabastecido com sucesso')
                    ->body("{$container->tipoLabel()} em {$container->piscina?->name} reposto para {$data['quantidade_l']} L.")
                    ->send();
            });
    }

    /**
     * Resumo executivo de KPIs para a barra superior.
     */
    public function getKpisProperty(): array
    {
        $produtos = Product::query()->where('active', true)->with(['stockArmazem', 'stockInstalacoes'])->get();
        $criticosCount = 0;
        $totalStockArmazemL = 0;
        $totalStockArmazemKg = 0;

        foreach ($produtos as $p) {
            $isCritico = false;
            foreach ($p->stockInstalacoes as $si) {
                if ((float) $si->quantity <= (float) $si->limite_minimo) {
                    $isCritico = true;
                    break;
                }
            }
            if ($isCritico) {
                $criticosCount++;
            }
            $qty = (float) ($p->stockArmazem?->quantity ?? 0);
            if (strtolower((string) $p->unidade) === 'kg') {
                $totalStockArmazemKg += $qty;
            } else {
                $totalStockArmazemL += $qty;
            }
        }

        $bicoes = DosingContainer::query()->with(['piscina', 'produto'])->get();
        $bicoesBaixosCount = $bicoes->filter(fn ($b) => $b->estaBaixo())->count();

        return [
            'criticos_count' => $criticosCount,
            'total_produtos' => $produtos->count(),
            'armazem_litros' => round($totalStockArmazemL, 1),
            'armazem_kg' => round($totalStockArmazemKg, 1),
            'bicoes_total' => $bicoes->count(),
            'bicoes_baixos' => $bicoesBaixosCount,
        ];
    }

    /**
     * Bidões ativos agrupados por instalação e piscina.
     */
    public function getBicoesGroupedProperty(): Collection
    {
        return DosingContainer::query()
            ->with(['piscina.instalacao', 'produto', 'reabastecidoPor'])
            ->orderBy('pool_id')
            ->get()
            ->groupBy(fn ($b) => $b->piscina?->instalacao?->name ?? 'Instalação');
    }

    /**
     * Histórico dos últimos 10 movimentos de armazém para auditoria rápida.
     */
    public function getUltimosMovimentosProperty(): Collection
    {
        return StockWarehouseLog::query()
            ->with(['produto', 'utilizador', 'armazem'])
            ->latest('created_at')
            ->limit(10)
            ->get();
    }
}
