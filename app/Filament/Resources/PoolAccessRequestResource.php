<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\PoolAccessRequestStatus;
use App\Constants\UserRole;
use App\Filament\Resources\PoolAccessRequestResource\Pages;
use App\Models\PoolAccessRequest;
use App\Services\PoolAccessRequestService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Pedidos de acesso de nadadores-salvadores bloqueados (todas as piscinas
 * atribuídas encerradas). Criar está desligado de propósito: o pedido nasce
 * sempre do ecrã de bloqueio (/piscinas-encerradas), nunca daqui — toda a
 * decisão passa por PoolAccessRequestService.
 */
class PoolAccessRequestResource extends Resource
{
    protected static ?string $model = PoolAccessRequest::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Pedidos de Acesso';

    protected static ?string $modelLabel = 'Pedido de Acesso';

    protected static ?string $pluralModelLabel = 'Pedidos de Acesso';

    protected static ?int $navigationSort = 5;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $pendentes = PoolAccessRequest::query()->pendentes()->count();

        return $pendentes > 0 ? (string) $pendentes : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(PoolAccessRequest::query()->with(['user.piscinas', 'decididoPor']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Nadador-Salvador')
                    ->weight('semibold')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.piscinas.name')
                    ->label('Piscina(s)')
                    ->badge()
                    ->separator(','),
                Tables\Columns\TextColumn::make('motivo')
                    ->label('Motivo do pedido')
                    ->limit(80)
                    ->wrap(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => PoolAccessRequestStatus::label($state))
                    ->color(fn (string $state): string => match ($state) {
                        PoolAccessRequestStatus::APROVADO => 'success',
                        PoolAccessRequestStatus::NEGADO => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Pedido em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('decididoPor.name')
                    ->label('Decidido por')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PoolAccessRequestStatus::LABELS)
                    ->default(PoolAccessRequestStatus::PENDENTE),
            ])
            ->actions([
                Tables\Actions\Action::make('aprovar')
                    ->label('Conceder acesso')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PoolAccessRequest $pedido): bool => $pedido->status === PoolAccessRequestStatus::PENDENTE)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('resposta')
                            ->label('Nota (opcional)')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (PoolAccessRequest $pedido, array $data): void {
                        app(PoolAccessRequestService::class)->aprovar($pedido, auth()->user(), $data['resposta'] ?? null);

                        Notification::make()->success()->title('Acesso concedido')->send();
                    }),

                Tables\Actions\Action::make('negar')
                    ->label('Negar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PoolAccessRequest $pedido): bool => $pedido->status === PoolAccessRequestStatus::PENDENTE)
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('resposta')
                            ->label('Motivo (opcional)')
                            ->rows(2)
                            ->maxLength(500),
                    ])
                    ->action(function (PoolAccessRequest $pedido, array $data): void {
                        app(PoolAccessRequestService::class)->negar($pedido, auth()->user(), $data['resposta'] ?? null);

                        Notification::make()->warning()->title('Pedido negado')->send();
                    }),

                Tables\Actions\Action::make('revogar')
                    ->label('Revogar acesso')
                    ->icon('heroicon-o-lock-closed')
                    ->color('gray')
                    ->visible(fn (PoolAccessRequest $pedido): bool => $pedido->status === PoolAccessRequestStatus::APROVADO)
                    ->requiresConfirmation()
                    ->modalDescription('O nadador-salvador volta a ficar bloqueado enquanto a piscina estiver encerrada.')
                    ->action(function (PoolAccessRequest $pedido): void {
                        app(PoolAccessRequestService::class)->negar($pedido, auth()->user(), 'Acesso revogado.');

                        Notification::make()->warning()->title('Acesso revogado')->send();
                    }),
            ])
            ->emptyStateHeading('Sem pedidos de acesso')
            ->emptyStateDescription('Aparecem aqui quando um nadador-salvador bloqueado pedir para verificar a app.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPoolAccessRequests::route('/'),
        ];
    }
}
