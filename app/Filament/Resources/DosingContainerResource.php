<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\PaginaGestor;
use App\Constants\UserRole;
use App\Filament\Resources\DosingContainerResource\Pages;
use App\Models\DosingContainer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Gestão dos bidões de reagente (cloro / pH-) de cada piscina. O nível desce
 * automaticamente com a dosagem do controlador; aqui configura-se a capacidade
 * e regista-se o reabastecimento.
 */
class DosingContainerResource extends Resource
{
    protected static ?string $model = DosingContainer::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Stock';

    protected static ?string $modelLabel = 'Bidão de dosagem';

    protected static ?string $pluralModelLabel = 'Bidões de dosagem';

    protected static ?int $navigationSort = 40;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO, UserRole::GESTOR])
            && $user->podeVerPagina(PaginaGestor::BIDOES_DOSAGEM);
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['tipo', 'piscina.name', 'produto.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return ($record->piscina?->name ?? 'Piscina').' — '.$record->tipoLabel();
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        $percentagem = $record->percentagem();

        return [
            'Nível' => $percentagem !== null ? number_format($percentagem, 1, ',', '').' %' : '—',
            'Capacidade' => $record->capacidade_ml !== null ? number_format($record->capacidade_ml / 1000, 1, ',', '').' L' : '—',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('piscina');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('pool_id')
                ->label('Piscina')
                ->relationship('piscina', 'name')
                ->searchable()
                ->preload()
                ->required(),

            Forms\Components\Select::make('tipo')
                ->label('Reagente')
                ->options(DosingContainer::TIPOS)
                ->required(),

            // Liga o bidão ao produto em stock: cada reabastecimento passa a
            // descontar do stock da instalação (antes o consumo real por dosagem
            // automática nunca aparecia em lado nenhum).
            Forms\Components\Select::make('product_id')
                ->label('Produto em stock')
                ->relationship('produto', 'name')
                ->searchable()
                ->preload()
                ->helperText('Produto debitado do stock da instalação em cada reabastecimento.'),

            Forms\Components\TextInput::make('capacidade_ml')
                ->label('Capacidade (L)')
                ->numeric()
                ->step('any')
                ->minValue(0)
                ->formatStateUsing(fn ($state) => $state === null ? null : $state / 1000)
                ->dehydrateStateUsing(fn ($state) => $state === null ? null : (int) ($state * 1000))
                ->helperText('Ex.: um bidão de 20 L. Necessária para calcular a percentagem.'),

            Forms\Components\TextInput::make('restante_ml')
                ->label('Restante (L)')
                ->numeric()
                ->step('any')
                ->minValue(0)
                ->default(0)
                ->formatStateUsing(fn ($state) => $state === null ? null : $state / 1000)
                ->dehydrateStateUsing(fn ($state) => $state === null ? null : round($state * 1000, 2))
                ->helperText('Nível atual. Normalmente ajustado pelo botão "Reabastecer".'),

            Forms\Components\TextInput::make('alerta_percent')
                ->label('Alerta abaixo de (%)')
                ->numeric()
                ->minValue(1)
                ->maxValue(100)
                ->default(20),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('60s')
            ->modifyQueryUsing(fn ($query) => $query->with('piscina.instalacao'))
            ->columns([
                Tables\Columns\TextColumn::make('piscina.name')
                    ->label('Piscina')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Reagente')
                    ->formatStateUsing(fn (DosingContainer $r) => $r->tipoLabel())
                    ->badge(),
                Tables\Columns\TextColumn::make('percentagem')
                    ->label('Nível')
                    ->badge()
                    ->state(fn (DosingContainer $r) => $r->percentagem() !== null
                        ? number_format($r->percentagem(), 0, ',', '').'%'
                        : '—')
                    ->color(fn (DosingContainer $r) => match ($r->nivel()) {
                        'critico' => 'danger',
                        'aviso' => 'warning',
                        'ok' => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('restante_ml')
                    ->label('Restante')
                    ->formatStateUsing(fn (DosingContainer $r) => number_format((float) $r->restante_ml / 1000, 2, ',', ' ').' L'),
                Tables\Columns\TextColumn::make('capacidade_ml')
                    ->label('Capacidade')
                    ->formatStateUsing(fn (DosingContainer $r) => $r->capacidade_ml !== null
                        ? number_format($r->capacidade_ml / 1000, 2, ',', ' ').' L'
                        : '—'),
                Tables\Columns\TextColumn::make('reabastecido_em')
                    ->label('Último reabastecimento')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->placeholder('—'),
            ])
            ->defaultSort('pool_id')
            ->actions([
                Tables\Actions\Action::make('reabastecer')
                    ->label('Reabastecer')
                    ->icon('heroicon-o-arrow-up-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('quantidade_l')
                            ->label('Nível após reabastecimento (L)')
                            ->numeric()
                            ->step('any')
                            ->minValue(0)
                            ->required()
                            ->default(fn (DosingContainer $record) => $record->capacidade_ml !== null ? $record->capacidade_ml / 1000 : null)
                            ->helperText('Por defeito, bidão cheio (capacidade).'),
                        Forms\Components\TextInput::make('nota')
                            ->label('Nota (opcional)')
                            ->maxLength(255),
                    ])
                    ->action(function (DosingContainer $record, array $data): void {
                        $record->reabastecer(
                            (float) $data['quantidade_l'] * 1000,
                            auth()->id(),
                            $data['nota'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title('Bidão reabastecido')
                            ->body("{$record->tipoLabel()} — {$record->piscina?->name}")
                            ->send();
                    }),

                Tables\Actions\Action::make('ajustar')
                    ->label('Ajustar nível')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray')
                    ->form([
                        Forms\Components\TextInput::make('restante_l')
                            ->label('Nível real medido (L)')
                            ->numeric()
                            ->step('any')
                            ->minValue(0)
                            ->required()
                            ->default(fn (DosingContainer $record) => $record->restante_ml !== null ? (float) $record->restante_ml / 1000 : 0),
                        Forms\Components\TextInput::make('nota')
                            ->label('Motivo do ajuste')
                            ->maxLength(255),
                    ])
                    ->action(function (DosingContainer $record, array $data): void {
                        $novo = round((float) $data['restante_l'] * 1000, 2);

                        $fresco = DB::transaction(function () use ($record, $novo, $data): DosingContainer {
                            // Lock só é efetivo dentro da transação; evita race se dois ajustarem em simultâneo.
                            $fresco = DosingContainer::lockForUpdate()->findOrFail($record->id);
                            $delta = $novo - (float) $fresco->restante_ml;

                            $fresco->update(['restante_ml' => $novo]);
                            $fresco->logs()->create([
                                'tipo_movimento' => 'ajuste',
                                'quantidade_ml' => $delta,
                                'restante_apos_ml' => $novo,
                                'origem' => 'manual',
                                'user_id' => auth()->id(),
                                'nota' => $data['nota'] ?? null,
                                'registado_em' => now(),
                            ]);

                            return $fresco;
                        });

                        // Um ajuste manual para nível baixo passa a alertar como o sync do controlador.
                        $fresco->notificarSeBaixo();

                        Notification::make()
                            ->success()
                            ->title('Nível ajustado')
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDosingContainers::route('/'),
            'create' => Pages\CreateDosingContainer::route('/create'),
            'edit' => Pages\EditDosingContainer::route('/{record}/edit'),
        ];
    }
}
