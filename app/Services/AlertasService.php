<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\IncidentResource;
use App\Filament\Resources\StockInstallationResource;
use App\Models\DailyRecord;
use App\Models\Incident;
use App\Models\Pool;
use App\Models\StockInstallation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Calcula os alertas operacionais do dia (exception-first) com chaves estáveis,
 * para alimentar o quadro Kanban do dashboard.
 *
 * Cada alerta: key, nivel (vermelho|amarelo|neutro), icone, titulo, detalhe,
 * url, acao. A chave é determinística (tipo|id|data) para o estado Kanban
 * sobreviver a recálculos.
 *
 * Disciplina de cor anti alarm-fatigue: vermelho SÓ para violação legal ou
 * falta de registo já tarde; amarelo para avisos; neutro para informativo.
 */
class AlertasService
{
    /** Memo por-pedido: o hero e o Kanban partilham o mesmo cálculo. */
    private static array $memo = [];

    /**
     * @return array{alertas: array<string, array<string, mixed>>, totalPiscinas: int, conformesHoje: int}
     */
    public function calcular(?User $utilizador): array
    {
        $memoKey = (string) ($utilizador?->id ?? 'guest');
        if (isset(self::$memo[$memoKey])) {
            return self::$memo[$memoKey];
        }

        $alertas = [];
        $soPiscinas = $utilizador?->hasRole('nadador_salvador') ?? false;
        $hoje = now()->toDateString();

        $piscinas = Pool::query()
            ->where('active', true)
            ->with('instalacao')
            ->orderBy('installation_id')
            ->orderBy('name')
            ->get();

        $conformesHoje = 0;

        // Torneiras abertas: uma query única fora do loop.
        $taps = Schema::hasTable('tap_alerts')
            ? DB::table('tap_alerts')->whereNull('resolved_at')->get()->groupBy('pool_id')
            : collect();

        foreach ($piscinas as $piscina) {
            $nome = $piscina->instalacao?->name
                ? "{$piscina->instalacao->name} {$piscina->name}"
                : $piscina->name;

            $registo = DailyRecord::query()
                ->where('pool_id', $piscina->id)
                ->whereDoesntHave('correcoes')
                ->orderByDesc('registado_em')
                ->orderByDesc('id')
                ->first();

            $temRegistoHoje = $registo && $registo->registado_em->isToday();

            if (! $temRegistoHoje) {
                $alertas["sem_registo|{$piscina->id}|{$hoje}"] = [
                    'nivel' => now()->hour >= 12 ? 'vermelho' : 'amarelo',
                    'icone' => 'heroicon-o-clipboard-document-list',
                    'titulo' => "{$nome}: sem registo diário hoje",
                    'detalhe' => $registo
                        ? 'Último registo em '.$registo->registado_em->format('d/m H:i')
                        : 'Nunca teve registos',
                    'url' => DailyRecordResource::getUrl('create'),
                    'acao' => 'Criar registo',
                ];
            }

            if ($registo) {
                $registo->setRelation('piscina', $piscina);

                $violacoes = $this->violacoesLegais($registo);
                $violacaoTemp = $this->violacaoTemperatura($registo, $piscina);

                if ($violacoes !== []) {
                    $alertas["fora_limites|{$registo->id}"] = [
                        'nivel' => 'vermelho',
                        'icone' => 'heroicon-o-beaker',
                        'titulo' => "{$nome}: parâmetros fora dos limites CN 14/DA",
                        'detalhe' => implode(' · ', $violacoes)
                            .' (registo de '.$registo->registado_em->format('d/m H:i').')',
                        'url' => DailyRecordResource::getUrl('view', ['record' => $registo]),
                        'acao' => 'Ver registo',
                    ];
                }

                if ($violacaoTemp !== null) {
                    $alertas["temp|{$registo->id}"] = [
                        'nivel' => 'amarelo',
                        'icone' => 'heroicon-o-fire',
                        'titulo' => "{$nome}: temperatura fora da gama da piscina",
                        'detalhe' => $violacaoTemp
                            .' (registo de '.$registo->registado_em->format('d/m H:i').')',
                        'url' => DailyRecordResource::getUrl('view', ['record' => $registo]),
                        'acao' => 'Ver registo',
                    ];
                }

                if ($temRegistoHoje && $violacoes === [] && $violacaoTemp === null) {
                    $conformesHoje++;
                }
            }

            foreach ($taps->get($piscina->id, collect()) as $tap) {
                $alertas["tap|{$tap->id}"] = [
                    'nivel' => 'amarelo',
                    'icone' => 'heroicon-o-exclamation-triangle',
                    'titulo' => "{$nome}: torneira de água aberta por resolver",
                    'detalhe' => 'Aberta desde '.Carbon::parse($tap->opened_at)->format('d/m H:i'),
                    'url' => DailyRecordResource::getUrl('create'),
                    'acao' => 'Registar fecho',
                ];
            }
        }

        if (! $soPiscinas) {
            $incidentes = Incident::query()
                ->where('status', '!=', 'resolvido')
                ->where('ocorreu_em', '>=', now()->subDays(30))
                ->with('instalacao')
                ->orderByDesc('ocorreu_em')
                ->limit(10)
                ->get();

            foreach ($incidentes as $incidente) {
                $alertas["incidente|{$incidente->id}"] = [
                    'nivel' => 'neutro',
                    'icone' => 'heroicon-o-bell-alert',
                    'titulo' => ($incidente->instalacao?->name ? "{$incidente->instalacao->name}: " : '')
                        .'incidente — '.($incidente->type ?: 'sem tipo'),
                    'detalhe' => $incidente->ocorreu_em->format('d/m H:i')
                        .($incidente->descricao ? ' · '.Str::limit($incidente->descricao, 80) : ''),
                    'url' => IncidentResource::getUrl('view', ['record' => $incidente]),
                    'acao' => 'Ver incidente',
                ];
            }

            $stockBaixo = StockInstallation::query()
                ->whereColumn('quantity', '<=', 'limite_minimo')
                ->count();

            if ($stockBaixo > 0) {
                $alertas["stock|{$hoje}"] = [
                    'nivel' => 'amarelo',
                    'icone' => 'heroicon-o-archive-box-x-mark',
                    'titulo' => $stockBaixo === 1
                        ? '1 produto com stock abaixo do mínimo'
                        : "{$stockBaixo} produtos com stock abaixo do mínimo",
                    'detalhe' => 'Detalhe na tabela "Alertas de Stock Baixo" mais abaixo',
                    'url' => StockInstallationResource::getUrl('index'),
                    'acao' => 'Ver stock',
                ];
            }
        }

        // Prioridade visual: vermelho > amarelo > neutro (ordem estável).
        $peso = ['vermelho' => 0, 'amarelo' => 1, 'neutro' => 2];
        uasort($alertas, fn (array $a, array $b) => $peso[$a['nivel']] <=> $peso[$b['nivel']]);

        return self::$memo[$memoKey] = [
            'alertas' => $alertas,
            'totalPiscinas' => $piscinas->count(),
            'conformesHoje' => $conformesHoje,
        ];
    }

    /** Invalida o memo (depois de mover um cartão no Kanban). */
    public static function limparMemo(): void
    {
        self::$memo = [];
    }

    /** @return array<int, string> */
    private function violacoesLegais(DailyRecord $registo): array
    {
        $violacoes = [];
        $fmt = fn (float $v, int $casas = 2): string => number_format($v, $casas, ',', '');

        // Leituras em falta (registos legados) não são violações — a falta de
        // registo recente já é coberta pelo alerta "sem registo diário hoje".
        if ($registo->ph !== null && ! $registo->phConforme()) {
            $ph = (float) $registo->ph;
            $violacoes[] = $ph < DailyRecord::PH_MIN
                ? 'pH '.$fmt($ph).' abaixo do mínimo ('.$fmt(DailyRecord::PH_MIN, 1).')'
                : 'pH '.$fmt($ph).' acima do máximo ('.$fmt(DailyRecord::PH_MAX, 1).')';
        }

        if ($registo->cloro_livre !== null && ! $registo->cloroLivreConforme()) {
            $cl = (float) $registo->cloro_livre;
            $violacoes[] = $cl < DailyRecord::CLORO_LIVRE_MIN
                ? 'cloro livre '.$fmt($cl).' mg/L abaixo do mínimo ('.$fmt(DailyRecord::CLORO_LIVRE_MIN, 1).')'
                : 'cloro livre '.$fmt($cl).' mg/L acima do máximo ('.$fmt(DailyRecord::CLORO_LIVRE_MAX, 1).')';
        }

        if ($registo->cloro_total !== null && $registo->cloro_livre !== null && ! $registo->cloroCombinadoConforme()) {
            $violacoes[] = 'cloro combinado '.$fmt((float) $registo->cloro_combinado)
                .' mg/L acima do máximo ('.$fmt(DailyRecord::CLORO_COMBINADO_MAX, 1).')';
        }

        return $violacoes;
    }

    private function violacaoTemperatura(DailyRecord $registo, Pool $piscina): ?string
    {
        if ($registo->temperatura === null || $registo->temperaturaConforme()) {
            return null;
        }

        $fmt = fn (float $v): string => number_format($v, 1, ',', '');
        $temp = (float) $registo->temperatura;

        return $temp < (float) $piscina->temp_min
            ? 'temperatura '.$fmt($temp).' °C abaixo do mínimo ('.$fmt((float) $piscina->temp_min).')'
            : 'temperatura '.$fmt($temp).' °C acima do máximo ('.$fmt((float) $piscina->temp_max).')';
    }
}
