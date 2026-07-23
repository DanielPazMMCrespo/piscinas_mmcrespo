<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\AlertLevel;
use App\Constants\UserRole;
use App\Models\AlertState;
use App\Services\AlertasService;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Quadro Kanban operacional (topo do dashboard): os alertas exception-first
 * organizados em três colunas — Para tratar / Em tratamento / Resolvido hoje.
 *
 * Os alertas são calculados (AlertasService); só o estado de tratamento é
 * persistido (AlertState), com chave estável. Quando a condição de um alerta
 * desaparece (ex: o registo em falta foi criado), o cartão passa sozinho
 * para "Resolvido hoje" com a marca "automático".
 *
 * Drag-and-drop (SortableJS) + botões de movimento (fallback mobile);
 * animações GSAP. Visível a todos os roles (o NS só vê alertas de piscinas).
 */
class QuadroOperacionalWidget extends Widget
{
    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.quadro-operacional';

    /** O estado muda ao longo da manhã (regra das 12h) — refresca a cada 30s. */
    protected static ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        return ! auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR);
    }

    /**
     * Move um alerta entre ativo e resolvido (chamado pelos botões "Resolver"/"Reabrir").
     */
    public function moverAlerta(string $key, string $status): void
    {
        try {
            app(AlertasService::class)->moverAlerta(auth()->user(), $key, $status);
        } catch (\DomainException $e) {
            Notification::make()
                ->title('Erro ao mover')
                ->body($e->getMessage())
                ->warning()
                ->send();
        }
    }

    protected function getViewData(): array
    {
        $resultado = Cache::remember(
            'alertas_'.auth()->id(),
            30,
            fn () => app(AlertasService::class)->calcular(auth()->user())
        );
        $ativos = $resultado['alertas'];

        // Poda: estados com mais de 7 dias já não interessam (corre no máximo 1x por hora).
        Cache::remember('alert_state_pruning', 3600, function () {
            AlertState::query()->where('moved_at', '<', now()->subDays(7))->delete();

            return true;
        });

        $estados = AlertState::query()
            ->where('status', '!=', 'resolvido')
            ->where('status', '!=', 'resolvido_auto')
            ->orWhere(function ($q) {
                $q->whereIn('status', ['resolvido', 'resolvido_auto'])
                    ->whereDate('moved_at', today());
            })
            ->get()
            ->keyBy('alert_key');

        $listaAtivos = [];
        $listaResolvidos = [];
        $semRegisto = [];
        $indiceGrupoSemRegisto = null;

        // Alertas ativos: qualquer status guardado que não seja resolvido/resolvido_auto
        // conta como ativo. Alertas "sem_registo" são agrupados num único cartão quando
        // há 2+ (evita encher o quadro com uma linha por piscina em falta).
        foreach ($ativos as $key => $alerta) {
            $estado = $estados->get($key);
            $resolvido = $estado && in_array($estado->status, ['resolvido', 'resolvido_auto'], true);

            if ($resolvido) {
                continue;
            }

            $item = $alerta + [
                'key' => $key,
                'auto' => false,
                'movido_em' => $estado?->moved_at?->format('H:i'),
            ];

            if (str_starts_with($key, 'sem_registo|')) {
                $semRegisto[] = $item;

                if ($indiceGrupoSemRegisto === null) {
                    $listaAtivos[] = null;
                    $indiceGrupoSemRegisto = array_key_last($listaAtivos);
                }

                continue;
            }

            $listaAtivos[] = $item;
        }

        if ($indiceGrupoSemRegisto !== null) {
            if (count($semRegisto) === 1) {
                $listaAtivos[$indiceGrupoSemRegisto] = $semRegisto[0];
            } else {
                $nomes = array_map(fn ($i) => trim(explode(':', $i['titulo'])[0]), $semRegisto);
                $temVermelho = collect($semRegisto)->contains(fn ($i) => $i['nivel'] === AlertLevel::VERMELHO);

                $listaAtivos[$indiceGrupoSemRegisto] = [
                    'key' => 'grupo_sem_registo',
                    'grupo' => true,
                    'nivel' => $temVermelho ? AlertLevel::VERMELHO : AlertLevel::AMARELO,
                    'icone' => 'heroicon-o-clipboard-document-list',
                    'titulo' => 'Sem registo diário hoje',
                    'detalhe' => count($semRegisto).' piscinas: '.implode(', ', $nomes),
                    'subalertas' => $semRegisto,
                ];
            }
        }

        $listaAtivos = array_values($listaAtivos);

        // Estados cuja condição desapareceu: resolvidos automáticos de hoje.
        DB::transaction(function () use ($estados, $ativos) {
            foreach ($estados as $key => $estado) {
                if (isset($ativos[$key])) {
                    continue;
                }

                if (! in_array($estado->status, ['resolvido', 'resolvido_auto'], true)) {
                    $estado->update(['status' => 'resolvido_auto', 'moved_at' => now()]);
                }
            }
        });

        foreach ($estados as $key => $estado) {
            if (isset($ativos[$key])) {
                continue;
            }

            if (! $estado->moved_at->isToday() || ! is_array($estado->payload)) {
                continue;
            }

            $listaResolvidos[] = ($estado->payload ?? []) + [
                'key' => $key,
                'nivel' => $estado->payload['nivel'] ?? AlertLevel::NEUTRO,
                'icone' => $estado->payload['icone'] ?? 'heroicon-o-check-circle',
                'titulo' => $estado->payload['titulo'] ?? 'Alerta resolvido',
                'detalhe' => $estado->payload['detalhe'] ?? 'A condição de alerta foi resolvida.',
                'url' => $estado->payload['url'] ?? '#',
                'acao' => $estado->payload['acao'] ?? '',
                'auto' => $estado->status === 'resolvido_auto',
                'movido_em' => $estado->moved_at->format('H:i'),
            ];
        }

        return [
            'ativos' => $listaAtivos,
            'resolvidos' => $listaResolvidos,
            'totalPiscinas' => $resultado['totalPiscinas'],
            'conformesHoje' => $resultado['conformesHoje'],
        ];
    }
}
