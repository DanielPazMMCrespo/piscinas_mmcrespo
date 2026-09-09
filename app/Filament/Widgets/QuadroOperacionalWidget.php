<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Constants\AlertLevel;
use App\Constants\IncidentStatus;
use App\Constants\UserRole;
use App\Models\AlertState;
use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Notifications\IncidentMessageNotification;
use App\Services\AlertasService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Quadro de alertas operacionais (dashboard): lista exception-first dos alertas
 * ativos + secção colapsável "Resolvidos hoje". Dois estados por alerta,
 * pendente e resolvido — o Kanban de três colunas com drag-and-drop (SortableJS)
 * e GSAP foi substituído por esta lista com botões Resolver/Reabrir.
 *
 * Os alertas são calculados (AlertasService); só o estado de tratamento é
 * persistido (AlertState), com chave estável. Quando a condição de um alerta
 * desaparece (ex: o registo em falta foi criado), o cartão passa sozinho
 * para "Resolvidos hoje" com a marca "automático".
 *
 * Visível a todos os roles exceto Nadador-Salvador.
 */
class QuadroOperacionalWidget extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.widgets.quadro-operacional';

    /** O estado muda ao longo da manhã (regra das 12h) — refresca a cada 30s. */
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return ! auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR);
    }

    /**
     * Só as violações legais exigem confirmação antes de sair do quadro — usado
     * pelo wire:confirm da vista.
     */
    public static function exigeConfirmacao(array $alerta): bool
    {
        return ($alerta['nivel'] ?? null) === AlertLevel::VERMELHO;
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

    public function resolveIncidentAction(): Action
    {
        return Action::make('resolveIncident')
            ->modalWidth('md')
            ->modalAlignment('center')
            ->modalHeading('Resolver Incidente')
            ->modalDescription('Como solucionou esta anomalia? (O alerta será arquivado)')
            ->modalSubmitActionLabel('Arquivar')
            ->modalIcon('heroicon-o-shield-check')
            ->form([
                Forms\Components\Textarea::make('resolucao')
                    ->hiddenLabel()
                    ->placeholder('Opcional: nota sobre como solucionou (ou deixe em branco para arquivar).')
                    ->rows(2)
                    ->extraInputAttributes(['class' => 'neo-input-large']),
            ])
            ->action(function (array $data, array $arguments): void {
                $incident = Incident::find($arguments['id'] ?? null);
                if (! $incident || $incident->status === IncidentStatus::RESOLVIDO) {
                    return;
                }

                $user = auth()->user();
                if (! $user?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
                    Notification::make()->danger()->title('Sem permissão para resolver incidentes.')->send();

                    return;
                }

                // Se for técnico com piscinas específicas atribuídas, só resolve das suas piscinas
                if ($user->hasRole(UserRole::TECNICO) && $user->piscinas()->exists() && $incident->pool_id !== null) {
                    if (! $user->piscinas()->where('pools.id', $incident->pool_id)->exists()) {
                        Notification::make()->danger()->title('Sem permissão para este incidente.')->send();

                        return;
                    }
                }

                $resolucao = filled($data['resolucao'] ?? null) ? $data['resolucao'] : 'Resolvido no painel de controlo.';

                $incident->update([
                    'status' => IncidentStatus::RESOLVIDO,
                    'resolvido_em' => now(),
                    'resolvido_por' => auth()->id(),
                    'resolucao' => $resolucao,
                ]);

                $texto = "Estado alterado para: Resolvido — {$resolucao}";

                IncidentMessage::create([
                    'incident_id' => $incident->id,
                    'user_id' => auth()->id(),
                    'tipo' => IncidentMessage::TIPO_SISTEMA,
                    'texto' => $texto,
                ]);

                \Illuminate\Support\Facades\Notification::send(
                    $incident->participantes(excluir: auth()->user()),
                    new IncidentMessageNotification($incident, auth()->user(), $texto)
                );

                Notification::make()->success()->title('Incidente resolvido')->send();
            });
    }

    public function resolveViolationAction(): Action
    {
        return Action::make('resolveViolation')
            ->requiresConfirmation()
            ->modalWidth('md')
            ->modalAlignment('center')
            ->modalHeading('Violação de Limite Legal')
            ->modalDescription('Este alerta reflete uma violação dos limites legais. Marcar como tratado apaga o alerta do quadro, mas não altera os valores registados na folha. Continuar?')
            ->modalSubmitActionLabel('Sim, marcar como tratado')
            ->action(function (array $arguments): void {
                $this->moverAlerta($arguments['key'], 'resolvido');
            });
    }

    protected function getViewData(): array
    {
        $resultado = Cache::remember(
            'alertas_'.auth()->id(),
            30,
            fn () => app(AlertasService::class)->calcular(auth()->user())
        );
        $ativos = $resultado['alertas'];

        // Poda de estados >7 dias é feita pelo comando agendado `alerts:housekeeping`
        // (routes/console.php), não aqui — um caminho de leitura de widget não deve
        // ter side-effects de escrita.
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
                if ($estado->moved_at?->isToday()) {
                    $listaResolvidos[] = $alerta + [
                        'key' => $key,
                        'auto' => $estado->status === 'resolvido_auto',
                        'movido_em' => $estado->moved_at->format('H:i'),
                        'condicao_persiste' => true,
                    ];
                }

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
