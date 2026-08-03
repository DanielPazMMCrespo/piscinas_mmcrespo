<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use App\Models\Pool;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Violações dos limites CN 14/DA no período, em lista.
 *
 * A pergunta de auditoria ("esta piscina andou fora dos limites nas últimas duas
 * semanas?") só se conseguia responder a olho, no gráfico, e falhava nos
 * parâmetros que não estavam nos eixos escolhidos.
 */
class ViolacoesPeriodoWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected static bool $isDiscovered = false;

    protected static ?string $heading = 'Violações dos limites no período';

    protected int|string|array $columnSpan = 'full';

    public ?string $piscina = null;

    public int $dias = 14;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    public function mudarPeriodo(int $dias): void
    {
        $this->dias = in_array($dias, [7, 14, 30], true) ? $dias : 14;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->consulta())
            ->columns([
                Tables\Columns\TextColumn::make('registado_em')
                    ->label('Quando')
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (DailyRecord $r): string => $r->registado_em->locale('pt')->diffForHumans())
                    ->sortable(),
                Tables\Columns\TextColumn::make('piscina.name')
                    ->label('Piscina')
                    ->sortable(),
                Tables\Columns\TextColumn::make('violacoes')
                    ->label('Fora dos limites')
                    ->badge()
                    ->color('danger')
                    ->getStateUsing(fn (DailyRecord $r): array => collect($r->listarViolacoes())
                        ->pluck('mensagem')
                        ->all()),
                Tables\Columns\TextColumn::make('utilizador.name')
                    ->label('Registado por')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('piscina')
                    ->label('Piscina')
                    ->options(fn (): array => Pool::query()->where('active', true)->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('pool_id', (int) $data['value'])
                        : $query),
                Tables\Filters\SelectFilter::make('dias')
                    ->label('Período')
                    ->options([7 => 'Últimos 7 dias', 14 => 'Últimos 14 dias', 30 => 'Últimos 30 dias'])
                    ->default(14)
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('registado_em', '>=', now()->subDays((int) $data['value']))
                        : $query),
            ])
            ->emptyStateHeading('Sem violações no período')
            ->emptyStateDescription('Todos os registos do período estão dentro dos limites CN 14/DA.')
            ->emptyStateIcon('heroicon-o-shield-check')
            ->defaultSort('registado_em', 'desc')
            ->paginated([10, 25, 50]);
    }

    /**
     * `listarViolacoes()` avalia limites configuráveis e cloro combinado em PHP —
     * não há condição SQL equivalente. Os IDs em falta são calculados uma vez
     * sobre a janela máxima (30 dias) e usados como filtro da query da tabela.
     */
    private function consulta(): Builder
    {
        $ids = DailyRecord::query()
            ->whereDoesntHave('correcoes')
            ->where('registado_em', '>=', now()->subDays(30))
            ->with('piscina')
            ->get()
            ->filter(fn (DailyRecord $r) => $r->piscina !== null && $r->listarViolacoes() !== [])
            ->pluck('id');

        return DailyRecord::query()
            ->whereIn('id', $ids)
            ->with(['piscina', 'utilizador']);
    }
}
