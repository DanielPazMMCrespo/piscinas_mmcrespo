<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Filament\Resources\PoolClosureResource;
use App\Models\PoolClosure;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Histórico de encerramentos no rodapé da página Operação → Encerramentos.
 *
 * [AI_CONTEXT]
 * - As colunas e filtros vêm de `PoolClosureResource::table()` — é a fonte
 *   única. Só as ações são substituídas: dentro de um widget não há formulário
 *   de resource, por isso o "Editar" é um link explícito para a rota do
 *   resource em vez de uma EditAction com modal.
 * - Read-only por design. Encerrar e reabrir continuam a passar pelo
 *   PoolClosureService, na tabela de cima.
 */
class HistoricoEncerramentosWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Histórico de encerramentos';

    public function table(Table $table): Table
    {
        return PoolClosureResource::table($table)
            // Fora do contexto de um Resource a tabela não sabe de onde vir os
            // registos — o `modifyQueryUsing` do Resource só refina esta query.
            ->query(fn () => PoolClosureResource::getEloquentQuery())
            ->description('Todos os encerramentos já registados, incluindo os que terminaram. Corrigir o motivo ou as observações faz-se em Editar.')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10, 25, 50])
            ->poll(null)
            ->actions([
                Tables\Actions\Action::make('editar')
                    ->label('Editar')
                    ->icon('heroicon-m-pencil-square')
                    ->color('gray')
                    ->url(fn (PoolClosure $record): string => PoolClosureResource::getUrl('edit', ['record' => $record]))
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR]) ?? false),
            ]);
    }
}
