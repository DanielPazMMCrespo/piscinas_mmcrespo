<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\MotivoEncerramento;
use App\Constants\UserRole;
use App\Filament\Resources\PoolClosureResource\Pages;
use App\Models\Pool;
use App\Models\PoolClosure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Histórico auditável de encerramentos. Criar e reabrir faz-se na página
 * Operação → Encerramentos (passa pelo PoolClosureService); aqui só se consulta,
 * se corrige o motivo/observações e — só admin — se apaga.
 */
class PoolClosureResource extends Resource
{
    protected static ?string $model = PoolClosure::class;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Logs';

    protected static ?string $modelLabel = 'Encerramento';

    protected static ?string $pluralModelLabel = 'Histórico de Encerramentos';

    protected static ?string $navigationLabel = 'Encerramentos';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]) ?? false;
    }

    public static function canCreate(): bool
    {
        // Encerrar passa sempre pelo serviço, na página de Encerramentos —
        // criar uma linha à mão aqui saltaria as validações de sobreposição.
        return false;
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['motivo', 'observacoes', 'piscina.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return ($record->piscina?->name ?? 'Piscina').' — '.$record->motivo_label;
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Período' => $record->descricao_periodo,
            'Estado' => $record->esta_vigente ? 'Encerrada' : 'Reaberta',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('piscina');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Encerramento')
                ->icon('heroicon-o-lock-closed')
                ->description('As datas não se editam aqui: reabrir uma piscina faz-se em Operação → Encerramentos.')
                ->schema([
                    Forms\Components\Placeholder::make('piscina_nome')
                        ->label('Piscina')
                        ->content(fn (?PoolClosure $record): string => $record?->piscina?->nome_completo ?? '—'),

                    Forms\Components\Placeholder::make('periodo')
                        ->label('Período')
                        ->content(fn (?PoolClosure $record): string => ucfirst($record?->descricao_periodo ?? '—')),

                    Forms\Components\Select::make('motivo')
                        ->label('Motivo')
                        ->options(MotivoEncerramento::labels())
                        ->required()
                        ->native(false),

                    Forms\Components\Toggle::make('agua_em_tratamento')
                        ->label('A água continua em tratamento'),

                    Forms\Components\Textarea::make('observacoes')
                        ->label('Observações')
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['piscina.instalacao', 'encerradaPor', 'reabertaPor'])
                ->orderByDesc('inicio'))
            ->columns([
                Tables\Columns\TextColumn::make('piscina.nome_completo')
                    ->label('Piscina')
                    ->weight('semibold')
                    ->searchable(['name'])
                    ->sortable(),
                Tables\Columns\TextColumn::make('motivo')
                    ->label('Motivo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => MotivoEncerramento::label($state))
                    ->color(fn (string $state): string => match ($state) {
                        MotivoEncerramento::EPOCA_BALNEAR => 'info',
                        MotivoEncerramento::AVARIA, MotivoEncerramento::ORDEM_AUTORIDADE => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('inicio')
                    ->label('Encerrada desde')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('fim')
                    ->label('Último dia encerrado')
                    ->date('d/m/Y')
                    ->placeholder('Em aberto')
                    ->sortable(),
                Tables\Columns\TextColumn::make('dias')
                    ->label('Dias')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\IconColumn::make('agua_em_tratamento')
                    ->label('Água tratada')
                    ->boolean(),
                Tables\Columns\TextColumn::make('encerradaPor.name')
                    ->label('Encerrada por')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reabertaPor.name')
                    ->label('Reaberta por')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('pool_id')
                    ->label('Piscina')
                    ->options(fn (): array => Pool::query()
                        ->with('instalacao')
                        ->orderBy('installation_id')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Pool $p): array => [$p->id => $p->nome_completo])
                        ->all())
                    ->searchable(),
                Tables\Filters\SelectFilter::make('motivo')
                    ->label('Motivo')
                    ->options(MotivoEncerramento::labels()),
                Tables\Filters\TernaryFilter::make('vigente')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Em vigor')
                    ->falseLabel('Terminados')
                    ->queries(
                        true: fn (Builder $query) => $query->vigenteEm(Carbon::now()),
                        false: fn (Builder $query) => $query->whereNotNull('fim')->whereDate('fim', '<', Carbon::now()),
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sem encerramentos registados')
            ->emptyStateDescription('Encerrar uma piscina faz-se em Operação → Encerramentos.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPoolClosures::route('/'),
            'edit' => Pages\EditPoolClosure::route('/{record}/edit'),
        ];
    }
}
