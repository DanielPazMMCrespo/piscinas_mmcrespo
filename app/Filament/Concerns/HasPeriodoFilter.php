<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Forms;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

trait HasPeriodoFilter
{
    /**
     * Filtro de intervalo de datas com o mês corrente por omissão. Sem ele,
     * responder a "quanto gastei este mês" obrigava a folhear páginas à mão.
     */
    protected static function filtroPeriodo(string $coluna = 'created_at', bool $mesCorrentePorOmissao = true): Filter
    {
        return Filter::make('periodo')
            ->label('Período')
            ->form([
                Forms\Components\DatePicker::make('de')
                    ->label('De')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->displayFormat('d/m/Y')
                    ->default($mesCorrentePorOmissao ? now()->startOfMonth()->toDateString() : null),
                Forms\Components\DatePicker::make('ate')
                    ->label('Até')
                    ->native(false)
                    ->closeOnDateSelection()
                    ->displayFormat('d/m/Y')
                    ->default($mesCorrentePorOmissao ? now()->toDateString() : null),
            ])
            ->query(function (Builder $query, array $data) use ($coluna): Builder {
                // Normalizar para 'Y-m-d': um valor com hora ("2026-08-01 03:24:00")
                // comparado por whereDate faz comparação de strings e exclui o dia.
                return $query
                    ->when($data['de'] ?? null, fn (Builder $q, $de) => $q->whereDate($coluna, '>=', \Illuminate\Support\Carbon::parse($de)->toDateString()))
                    ->when($data['ate'] ?? null, fn (Builder $q, $ate) => $q->whereDate($coluna, '<=', \Illuminate\Support\Carbon::parse($ate)->toDateString()));
            })
            ->indicateUsing(function (array $data): ?string {
                if (blank($data['de'] ?? null) && blank($data['ate'] ?? null)) {
                    return null;
                }

                $de = filled($data['de'] ?? null) ? \Illuminate\Support\Carbon::parse($data['de'])->format('d/m/Y') : '…';
                $ate = filled($data['ate'] ?? null) ? \Illuminate\Support\Carbon::parse($data['ate'])->format('d/m/Y') : '…';

                return "Período: {$de} — {$ate}";
            });
    }
}
