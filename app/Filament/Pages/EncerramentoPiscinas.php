<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\MotivoEncerramento;
use App\Constants\UserRole;
use App\Models\Pool;
use App\Models\PoolClosure;
use App\Services\PoolClosureService;
use DomainException;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Encerrar e reabrir piscinas. Vive em Operação (e não na PoolResource, que é
 * território admin em Estrutura) porque quem fecha a época balnear é o gestor
 * ou o técnico.
 *
 * [AI_CONTEXT]
 * - Toda a escrita passa pelo PoolClosureService — nunca criar/editar um
 *   PoolClosure diretamente daqui.
 * - O encerramento não apaga nada: os registos e o histórico do período ficam,
 *   e o livro sanitário passa a imprimir a justificação dos dias sem registos.
 */
class EncerramentoPiscinas extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-lock-closed';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Encerramentos';

    protected static ?string $title = 'Encerramento de Piscinas';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.encerramento-piscinas';

    protected static ?string $slug = 'encerramentos';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]) ?? false;
    }

    private static function podeEncerrar(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Pool::query()
                    ->where('active', true)
                    ->with(['instalacao', 'encerramentos.encerradaPor'])
            )
            ->defaultSort('installation_id')
            ->defaultGroup('instalacao.name')
            ->paginated(false)
            ->poll(null)
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Piscina')
                    ->weight('semibold')
                    ->searchable(),
                Tables\Columns\TextColumn::make('estado_operacional')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        Pool::ESTADO_ENCERRADA => 'Encerrada',
                        Pool::ESTADO_DESATIVADA => 'Desativada',
                        default => 'Aberta',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        Pool::ESTADO_ENCERRADA => 'warning',
                        Pool::ESTADO_DESATIVADA => 'gray',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('motivo_atual')
                    ->label('Motivo')
                    ->getStateUsing(fn (Pool $piscina): string => $piscina->encerramentoEm()?->motivo_label ?? '—'),
                Tables\Columns\TextColumn::make('periodo_atual')
                    ->label('Período')
                    ->getStateUsing(fn (Pool $piscina): string => $piscina->encerramentoEm()?->descricao_periodo ?? '—'),
                Tables\Columns\IconColumn::make('agua_em_tratamento')
                    ->label('Água tratada')
                    ->getStateUsing(fn (Pool $piscina): bool => (bool) $piscina->encerramentoEm()?->agua_em_tratamento)
                    ->boolean()
                    ->tooltip(fn (Pool $piscina): ?string => $piscina->estaEncerradaEm()
                        ? ($piscina->encerramentoEm()?->agua_em_tratamento
                            ? 'Fechada ao público, química mantida — os registos diários continuam possíveis.'
                            : 'Piscina parada — os registos diários estão bloqueados.')
                        : null),
                Tables\Columns\TextColumn::make('encerrada_por_nome')
                    ->label('Encerrada por')
                    ->getStateUsing(fn (Pool $piscina): string => $piscina->encerramentoEm()?->encerradaPor?->name ?? '—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('encerradas')
                    ->label('Estado')
                    ->placeholder('Todas')
                    ->trueLabel('Só encerradas')
                    ->falseLabel('Só abertas')
                    ->queries(
                        true: fn (Builder $query) => $query->encerradasEm(Carbon::now()),
                        false: fn (Builder $query) => $query->naoEncerradasEm(Carbon::now()),
                    ),
            ])
            ->actions([
                Tables\Actions\Action::make('encerrar')
                    ->label('Encerrar')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (Pool $piscina): bool => self::podeEncerrar() && ! $piscina->estaEncerradaEm())
                    ->modalHeading(fn (Pool $piscina): string => "Encerrar {$piscina->nome_completo}")
                    ->modalSubmitActionLabel('Encerrar piscina')
                    ->form(self::formularioEncerramento())
                    ->action(function (Pool $piscina, array $data): void {
                        $this->encerrarPiscinas(collect([$piscina]), $data);
                    }),

                Tables\Actions\Action::make('reabrir')
                    ->label('Reabrir')
                    ->icon('heroicon-o-lock-open')
                    ->color('success')
                    ->visible(fn (Pool $piscina): bool => self::podeEncerrar() && $piscina->estaEncerradaEm())
                    ->modalHeading(fn (Pool $piscina): string => "Reabrir {$piscina->nome_completo}")
                    ->modalSubmitActionLabel('Reabrir piscina')
                    ->form([
                        Forms\Components\DatePicker::make('data_reabertura')
                            ->label('Aberta a partir de')
                            ->default(Carbon::now())
                            ->required()
                            ->native(false)
                            ->helperText('A piscina passa a contar como aberta neste dia. O dia anterior fica como último dia encerrado.'),
                    ])
                    ->action(function (Pool $piscina, array $data): void {
                        try {
                            $encerramento = app(PoolClosureService::class)->reabrir(
                                $piscina,
                                auth()->user(),
                                Carbon::parse($data['data_reabertura']),
                            );
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title('Não foi possível reabrir')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title("{$piscina->nome_completo} reaberta")
                            ->body($encerramento === null
                                ? 'O encerramento tinha sido criado hoje e ainda não produziu efeito — foi removido.'
                                : 'Último dia encerrado: '.$encerramento->fim->format('d/m/Y').'.')
                            ->send();
                    }),

                Tables\Actions\Action::make('historico')
                    ->label('Histórico')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Pool $piscina): string => "Encerramentos de {$piscina->nome_completo}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fechar')
                    ->modalContent(fn (Pool $piscina) => view('filament.pages.partials.historico-encerramentos', [
                        'encerramentos' => $piscina->encerramentos()->with(['encerradaPor', 'reabertaPor'])->get(),
                    ])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('encerrar_lote')
                    ->label('Encerrar selecionadas')
                    ->icon('heroicon-o-lock-closed')
                    ->color('warning')
                    ->visible(fn (): bool => self::podeEncerrar())
                    ->modalHeading('Encerrar piscinas selecionadas')
                    ->modalDescription('Fim de época balnear: fecha várias piscinas de uma vez com o mesmo motivo e período.')
                    ->modalSubmitActionLabel('Encerrar piscinas')
                    ->form(self::formularioEncerramento())
                    ->action(function (EloquentCollection $records, array $data): void {
                        $this->encerrarPiscinas($records, $data);
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->emptyStateHeading('Sem piscinas ativas')
            ->emptyStateDescription('As piscinas são criadas em Estrutura → Piscinas.');
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    private static function formularioEncerramento(): array
    {
        return [
            Forms\Components\Select::make('motivo')
                ->label('Motivo')
                ->options(MotivoEncerramento::labels())
                ->default(MotivoEncerramento::EPOCA_BALNEAR)
                ->required()
                ->native(false)
                ->live(),

            Forms\Components\DatePicker::make('inicio')
                ->label('Encerrada desde')
                ->default(Carbon::now())
                ->required()
                ->native(false)
                ->helperText('Primeiro dia em que a piscina esteve fechada. Pode ser retroativo.'),

            Forms\Components\DatePicker::make('fim')
                ->label('Prevista reabrir a (opcional)')
                ->native(false)
                ->afterOrEqual('inicio')
                ->helperText('Último dia encerrado. Deixe vazio se ainda não há data — reabre-se depois com um clique.'),

            Forms\Components\Toggle::make('agua_em_tratamento')
                ->label('A água continua em tratamento')
                ->default(fn (Get $get): bool => $get('motivo') !== MotivoEncerramento::EPOCA_BALNEAR)
                ->helperText('Ligado: fechada ao público mas com pH/cloro mantidos — os registos diários continuam possíveis e as violações legais continuam a alertar. Desligado: piscina parada ou vazia — registos diários bloqueados.'),

            Forms\Components\Textarea::make('observacoes')
                ->label('Observações')
                ->rows(2)
                ->maxLength(1000)
                ->helperText('Fica no livro sanitário, junto à declaração de encerramento.'),
        ];
    }

    /**
     * @param  Collection<int, Pool>|EloquentCollection<int, Pool>  $piscinas
     * @param  array<string, mixed>  $data
     */
    private function encerrarPiscinas($piscinas, array $data): void
    {
        $resultado = app(PoolClosureService::class)->encerrarEmLote(
            collect($piscinas),
            $data,
            auth()->user(),
        );

        $encerradas = $resultado['encerradas']->count();
        $erros = $resultado['erros'];

        if ($encerradas > 0) {
            Notification::make()
                ->success()
                ->title($encerradas === 1 ? 'Piscina encerrada' : "{$encerradas} piscinas encerradas")
                ->body(MotivoEncerramento::label($data['motivo'] ?? null).'. A equipa foi notificada.')
                ->send();
        }

        if ($erros !== []) {
            Notification::make()
                ->danger()
                ->title(count($erros) === 1 ? '1 piscina não foi encerrada' : count($erros).' piscinas não foram encerradas')
                ->body(collect($erros)->map(fn (string $erro, string $nome) => "{$nome}: {$erro}")->implode(' '))
                ->persistent()
                ->send();
        }
    }

    /**
     * Contagem para o cabeçalho da página.
     */
    public function getResumo(): array
    {
        $encerradas = Pool::query()->where('active', true)->encerradasEm(Carbon::now())->count();
        $abertas = Pool::operacionais()->count();

        return [
            'abertas' => $abertas,
            'encerradas' => $encerradas,
            'proxima_reabertura' => PoolClosure::query()
                ->vigenteEm(Carbon::now())
                ->whereNotNull('fim')
                ->orderBy('fim')
                ->first()?->fim,
        ];
    }
}
