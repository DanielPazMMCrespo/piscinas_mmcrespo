<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\UserRole;
use App\Models\DailyRecord;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Score de Conformidade: percentagem de registos sem violações legais no
 * período. KPI de gestão para auditorias CN 14/DA — mede a qualidade da água.
 *
 * Um número global não respondia à pergunta que se faz na prática ("qual é a
 * pior piscina deste mês?"), por isso há uma linha por piscina, ordenada da pior
 * para a melhor, e um seletor de 7/30 dias.
 */
class ScoreConformidadeWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public int $dias = 7;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    public function mudarPeriodo(int $dias): void
    {
        $this->dias = in_array($dias, [7, 30], true) ? $dias : 7;
    }

    protected function getStats(): array
    {
        $registos = DailyRecord::query()
            ->where('registado_em', '>=', now()->subDays($this->dias))
            ->whereDoesntHave('correcoes')
            ->with('piscina')
            ->get()
            ->filter(fn (DailyRecord $r) => $r->piscina !== null);

        $sufixo = "({$this->dias} dias)";

        if ($registos->isEmpty()) {
            return [
                Stat::make("Conformidade geral {$sufixo}", '—')
                    ->description('Sem registos no período')
                    ->color('gray'),
            ];
        }

        $conformes = $registos->filter(fn (DailyRecord $r) => empty($r->listarViolacoes()));
        $scoreGeral = round(($conformes->count() / $registos->count()) * 100, 1);

        $stats = [
            Stat::make("Conformidade geral {$sufixo}", number_format($scoreGeral, 1, ',', '').'%')
                ->description($registos->count().' registo(s) avaliado(s) · toque para trocar 7/30 dias')
                ->descriptionIcon('heroicon-m-shield-check')
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'wire:click' => 'mudarPeriodo('.($this->dias === 7 ? 30 : 7).')',
                ])
                ->color($this->cor($scoreGeral)),
        ];

        // Por piscina, da pior para a melhor: é a ordem em que interessa agir.
        $porPiscina = $registos
            ->groupBy(fn (DailyRecord $r) => $r->piscina->id)
            ->map(function ($doPool) {
                $total = $doPool->count();
                $ok = $doPool->filter(fn (DailyRecord $r) => empty($r->listarViolacoes()))->count();

                return [
                    'nome' => $doPool->first()->piscina->name,
                    'score' => round(($ok / $total) * 100, 1),
                    'total' => $total,
                    'fora' => $total - $ok,
                ];
            })
            ->sortBy('score');

        foreach ($porPiscina as $linha) {
            $stats[] = Stat::make($linha['nome'], number_format($linha['score'], 1, ',', '').'%')
                ->description($linha['fora'] > 0
                    ? $linha['fora'].' de '.$linha['total'].' registo(s) fora dos limites'
                    : $linha['total'].' registo(s), todos conformes')
                ->descriptionIcon($linha['fora'] > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($this->cor($linha['score']));
        }

        return $stats;
    }

    private function cor(float $score): string
    {
        return $score >= 95 ? 'success' : ($score >= 85 ? 'warning' : 'danger');
    }
}
