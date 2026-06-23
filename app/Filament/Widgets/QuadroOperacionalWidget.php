<?php declare(strict_types=1);
namespace App\Filament\Widgets;


use App\Models\AlertState;
use App\Services\AlertasService;
use Filament\Widgets\Widget;

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

    /** O estado muda ao longo da manhã (regra das 12h) — refresca a cada 60s. */
    protected static ?string $pollingInterval = '60s';

    /**
     * Move um cartão para outra coluna (chamado pelo drag-and-drop e botões).
     */
    public function moverAlerta(string $key, string $status): void
    {
        if (! in_array($status, ['pendente', 'em_curso', 'resolvido'], true)) {
            return;
        }

        $ativos = app(AlertasService::class)->calcular(auth()->user())['alertas'];

        AlertState::updateOrCreate(
            ['alert_key' => $key],
            [
                'status' => $status,
                // Snapshot para o cartão continuar legível depois de a condição sumir.
                'payload' => $ativos[$key] ?? null,
                'moved_by' => auth()->id(),
                'moved_at' => now(),
            ],
        );
    }

    protected function getViewData(): array
    {
        $resultado = app(AlertasService::class)->calcular(auth()->user());
        $ativos = $resultado['alertas'];

        // Poda: estados com mais de 7 dias já não interessam ao quadro (corre no máximo 1x por hora).
        \Illuminate\Support\Facades\Cache::remember('alert_state_pruning', 3600, function () {
            AlertState::query()->where('moved_at', '<', now()->subDays(7))->delete();
            return true;
        });

        $estados = AlertState::query()
            ->whereIn('status', ['pendente', 'em_curso'])
            ->orWhere(function ($q) {
                $q->whereIn('status', ['resolvido', 'resolvido_auto'])
                    ->whereDate('moved_at', today());
            })
            ->get()
            ->keyBy('alert_key');

        $colunas = ['pendente' => [], 'em_curso' => [], 'resolvido' => []];

        // Alertas ativos: a coluna vem do estado guardado (default: pendente).
        foreach ($ativos as $key => $alerta) {
            $estado = $estados->get($key);
            $status = $estado?->status ?? 'pendente';
            $coluna = in_array($status, ['resolvido', 'resolvido_auto'], true) ? 'resolvido' : $status;

            $colunas[$coluna][] = $alerta + [
                'key' => $key,
                'auto' => false,
                'movido_em' => $estado?->moved_at?->format('H:i'),
            ];
        }

        // Estados cuja condição desapareceu: resolvidos automáticos de hoje.
        foreach ($estados as $key => $estado) {
            if (isset($ativos[$key])) {
                continue;
            }

            if (in_array($estado->status, ['pendente', 'em_curso'], true)) {
                $estado->update(['status' => 'resolvido_auto', 'moved_at' => now()]);
            }

            if (! $estado->moved_at->isToday() || ! is_array($estado->payload)) {
                continue;
            }

            $colunas['resolvido'][] = $estado->payload + [
                'key' => $key,
                'auto' => $estado->status === 'resolvido_auto',
                'movido_em' => $estado->moved_at->format('H:i'),
            ];
        }

        return [
            'colunas' => $colunas,
            'totalPiscinas' => $resultado['totalPiscinas'],
            'conformesHoje' => $resultado['conformesHoje'],
        ];
    }
}
