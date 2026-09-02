<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DailyRecord;
use App\Models\RecordPhoto;
use App\Models\StockInstallation;
use App\Models\StockInstallationLog;
use App\Models\TapAlert;
use App\Models\User;
use App\Notifications\NaoConformidadeNotification;
use App\Support\Auditoria;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Throwable;

class ProcessDailyRecordAfterCreate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $dailyRecordId, public int $actorUserId)
    {
        $this->onQueue('daily-records');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 120];
    }

    public function handle(): void
    {
        $registo = DailyRecord::query()
            ->with(['piscina.instalacao', 'adicoes.produto'])
            ->findOrFail($this->dailyRecordId);

        $this->guardarFotos($registo);
        $this->descontarStock($registo);
        $this->notificarNaoConformidade($registo);
        $this->gerirTorneira($registo);
    }

    /**
     * As 3 tentativas esgotaram-se: stock não foi descontado, a torneira não
     * foi aberta/fechada e a não-conformidade (se houver) nunca chegou a
     * notificar ninguém. Um `Log::error` sozinho não chega — `storage/logs` é
     * efémero no Railway e ninguém tem acesso a ele pelo painel. Escreve-se
     * na Auditoria (fica visível e permanente) e avisa-se os admins pelo
     * mesmo canal usado noutras falhas de processamento em fila (ver
     * `HannaCloudSync::notificarFalhaDeAutenticacao()` e `descontarStock()`
     * acima, no mesmo ficheiro).
     */
    public function failed(Throwable $exception): void
    {
        Log::error('daily_record_post_processing_failed', [
            'daily_record_id' => $this->dailyRecordId,
            'actor_user_id' => $this->actorUserId,
            'exception_class' => $exception::class,
            'exception_code' => $exception->getCode(),
        ]);

        $registo = DailyRecord::query()->with('piscina')->find($this->dailyRecordId);
        $nomePiscina = $registo?->piscina?->nome_completo ?? "piscina #{$registo?->pool_id}";

        Auditoria::sistema(
            "Processamento pós-registo falhou definitivamente (registo #{$this->dailyRecordId}, {$nomePiscina}): {$exception->getMessage()}",
            [
                'daily_record_id' => $this->dailyRecordId,
                'pool_id' => $registo?->pool_id,
                'actor_user_id' => $this->actorUserId,
                'exception_class' => $exception::class,
            ],
        );

        $destinatarios = User::role('admin')->get();
        if ($destinatarios->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->title('Processamento do registo diário falhou')
            ->body("Registo #{$this->dailyRecordId} ({$nomePiscina}) esgotou as tentativas. Stock, torneira e não-conformidade podem não ter sido processados. Erro: {$exception->getMessage()}")
            ->sendToDatabase($destinatarios);
    }

    private function gerirTorneira(DailyRecord $registo): void
    {
        if (! $registo->pool_id) {
            return;
        }

        $aberto = TapAlert::query()
            ->where('pool_id', $registo->pool_id)
            ->whereNull('resolved_at')
            ->latest('opened_at')
            ->first();

        if ($registo->agua_modo === 'on_com_agua') {
            if (! $aberto) {
                TapAlert::create([
                    'pool_id' => $registo->pool_id,
                    'opened_record_id' => $registo->id,
                    'opened_by' => $registo->user_id,
                    'opened_at' => $registo->registado_em,
                ]);
            }

            return;
        }

        if ($registo->agua_modo === null || $registo->agua_modo === '') {
            return;
        }

        if ($aberto) {
            $aberto->update([
                'resolved_at' => $registo->registado_em,
                'resolved_by' => $registo->user_id,
                'resolved_record_id' => $registo->id,
                'resolution' => 'registo_seguinte',
            ]);
        }
    }

    private function guardarFotos(DailyRecord $registo): void
    {
        if (empty($registo->analises_fotos) || ! is_array($registo->analises_fotos)) {
            return;
        }

        foreach ($registo->analises_fotos as $caminho) {
            RecordPhoto::create([
                'daily_record_id' => $registo->id,
                'type' => 'tecnico',
                'path' => (string) $caminho,
            ]);
        }
    }

    private function descontarStock(DailyRecord $registo): void
    {
        $instalacaoId = $registo->piscina?->instalacao?->id;
        if (! $instalacaoId || $registo->adicoes->isEmpty()) {
            return;
        }

        $insuficientes = [];

        DB::transaction(function () use ($registo, $instalacaoId, &$insuficientes): void {
            foreach ($registo->adicoes as $adicao) {
                if (! $adicao->product_id || (float) $adicao->quantity <= 0) {
                    continue;
                }

                $nomeProduto = $adicao->produto?->name ?? 'produto';

                // Upsert atómico: INSERT ... ON CONFLICT DO NOTHING (PostgreSQL).
                StockInstallation::upsert(
                    [['installation_id' => $instalacaoId, 'product_id' => $adicao->product_id, 'quantity' => 0, 'limite_minimo' => 0]],
                    ['installation_id', 'product_id'],
                    [] // não actualiza nada se já existir
                );

                $stock = StockInstallation::query()
                    ->where('installation_id', $instalacaoId)
                    ->where('product_id', $adicao->product_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $pedido = (float) $adicao->quantity;
                $disponivel = (float) $stock->quantity;
                $consumo = min($pedido, $disponivel);

                if ($pedido > $disponivel) {
                    $insuficientes[] = $nomeProduto;
                }

                if ($consumo > 0) {
                    $stock->quantity = $disponivel - $consumo;
                    $stock->save();

                    StockInstallationLog::create([
                        'stock_installation_id' => $stock->id,
                        'user_id' => $this->actorUserId,
                        'tipo_movimento' => 'consumo',
                        'quantity' => $consumo,
                        'created_at' => now(),
                    ]);
                }
            }
        });

        if ($insuficientes === []) {
            return;
        }

        Auditoria::registar(
            Auditoria::CANAL_STOCK,
            'Consumo registado com stock insuficiente na instalação.',
            [
                'instalacao' => $registo->piscina?->instalacao?->name,
                'produtos' => array_values(array_unique($insuficientes)),
            ],
            alvo: $registo,
            autor: $registo->utilizador,
        );

        // Quem submeteu tem de saber que o stock não cobriu o consumo: só os
        // admins eram avisados e o técnico via apenas "Registo guardado!".
        $destinatarios = User::role('admin')->get();

        if ($registo->utilizador !== null && ! $destinatarios->contains('id', $registo->user_id)) {
            $destinatarios->push($registo->utilizador);
        }

        if ($destinatarios->isEmpty()) {
            return;
        }

        $corpo = 'Stock insuficiente na instalação '
            .($registo->piscina?->instalacao?->name ?? '')
            .' para: '.implode(', ', array_unique($insuficientes)).'.'
            .' O consumo foi registado até esgotar o stock disponível.';

        Notification::make()
            ->warning()
            ->title('Stock insuficiente')
            ->body($corpo)
            ->sendToDatabase($destinatarios);
    }

    private function notificarNaoConformidade(DailyRecord $registo): void
    {
        $violacoes = array_column($registo->listarViolacoes(), 'mensagem');

        if ($violacoes === []) {
            return;
        }

        $nome = $registo->piscina
            ? $registo->piscina->nome_completo
            : 'piscina';

        $destinatarios = User::role('admin')->get();
        if ($destinatarios->isEmpty()) {
            return;
        }

        NotificationFacade::send($destinatarios, new NaoConformidadeNotification($registo, $violacoes, $nome));
    }
}
