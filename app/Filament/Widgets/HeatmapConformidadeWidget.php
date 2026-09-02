<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\MotivoEncerramento;
use App\Constants\UserRole;
use App\Enums\EstadoConformidade;
use App\Models\DailyRecord;
use App\Models\Pool;
use App\Services\PoolClosureService;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Heatmap semanal: grelha 7 dias x piscinas, cor = pior conformidade do dia.
 * Dá visibilidade rápida a padrões recorrentes (ex.: piscina que viola pH
 * sempre à segunda) para auditorias CN 14/DA.
 */
class HeatmapConformidadeWidget extends Widget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    protected static string $view = 'filament.widgets.heatmap-conformidade';

    private const DIAS = 7;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
    }

    /**
     * @return array{dias: array<int, string>, piscinas: array<int, array{nome: string, celulas: array<int, array{estado: string, cor: string}>}>}
     */
    public function getDados(): array
    {
        $inicio = now()->subDays(self::DIAS - 1)->startOfDay();

        $dias = collect(range(0, self::DIAS - 1))
            ->map(fn (int $i) => $inicio->copy()->addDays($i));

        $piscinas = Pool::query()->where('active', true)->orderBy('installation_id')->orderBy('name')->get();

        // Um dia encerrado não é "sem dados": tem um estado próprio, senão o
        // heatmap acusa falha do operador num período em que a piscina estava
        // legitimamente fechada.
        $encerramentos = app(PoolClosureService::class)->mapa(
            $piscinas->pluck('id'),
            $inicio,
            $inicio->copy()->addDays(self::DIAS - 1),
        );

        $registos = DailyRecord::query()
            ->whereIn('pool_id', $piscinas->pluck('id'))
            ->whereDoesntHave('correcoes')
            ->where('registado_em', '>=', $inicio)
            ->with('piscina')
            ->get()
            ->groupBy(fn (DailyRecord $r) => $r->pool_id.'_'.$r->registado_em->toDateString());

        $linhas = $piscinas->map(function (Pool $piscina) use ($dias, $registos, $encerramentos) {
            $celulas = $dias->map(function (Carbon $dia) use ($piscina, $registos, $encerramentos) {
                $chave = $piscina->id.'_'.$dia->toDateString();
                $registosDoDia = $registos->get($chave);

                $encerramento = PoolClosureService::encerramentoNoMapa($encerramentos, $piscina->id, $dia);

                if ($encerramento !== null && ($registosDoDia === null || $registosDoDia->isEmpty())) {
                    return [
                        'estado' => 'encerrada',
                        'cor' => '#94a3b8',
                        'titulo' => 'Encerrada — '.MotivoEncerramento::label($encerramento['motivo']),
                    ];
                }

                if ($registosDoDia === null || $registosDoDia->isEmpty()) {
                    return ['estado' => 'sem_dados', 'cor' => '#e5e7eb', 'titulo' => 'Sem registos'];
                }

                $piorEstado = EstadoConformidade::VERDE;
                $mensagens = [];

                foreach ($registosDoDia as $registo) {
                    foreach (['ph' => $registo->ph_efetivo, 'cloro_livre' => $registo->cloro_livre_efetivo, 'cloro_combinado' => $registo->cloro_combinado, 'temperatura' => $registo->temperatura_efetivo] as $campo => $valor) {
                        if ($valor === null) {
                            continue;
                        }
                        $eval = DailyRecord::avaliarConformidade(
                            $campo,
                            $valor,
                            $piscina,
                            ph: $registo->ph_efetivo !== null ? (float) $registo->ph_efetivo : null,
                            data: $registo->registado_em,
                        );
                        if ($eval['estado'] === EstadoConformidade::VERMELHO) {
                            $piorEstado = EstadoConformidade::VERMELHO;
                            $mensagens[] = $eval['mensagem'];
                        } elseif ($eval['estado'] === EstadoConformidade::AMARELO && $piorEstado !== EstadoConformidade::VERMELHO) {
                            $piorEstado = EstadoConformidade::AMARELO;
                            $mensagens[] = $eval['mensagem'];
                        }
                    }
                }

                $cor = match ($piorEstado) {
                    EstadoConformidade::VERMELHO => '#dc2626',
                    EstadoConformidade::AMARELO => '#f59e0b',
                    default => '#16a34a',
                };

                return [
                    'estado' => $piorEstado,
                    'cor' => $cor,
                    'titulo' => $mensagens === [] ? 'Conforme' : implode(' · ', array_unique($mensagens)),
                ];
            })->values()->all();

            return [
                'nome' => $piscina->nomeCompleto(' — '),
                'celulas' => $celulas,
            ];
        })->values()->all();

        return [
            'dias' => $dias->map(fn (Carbon $d) => $d->locale('pt')->isoFormat('ddd DD/MM'))->all(),
            'piscinas' => $linhas,
        ];
    }
}
