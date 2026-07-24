<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyRecordResource;

use App\Constants\UserRole;
use App\Enums\EstadoConformidade;
use App\Models\DailyRecord;
use Closure;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Tabs;
use Filament\Infolists\Components\Tabs\Tab;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class DailyRecordTableBuilder
{
    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['piscina.instalacao', 'utilizador', 'adicoes.produto', 'fotos'])
                ->withCount('correcoes')
            )
            ->defaultSort('registado_em', 'desc')
            ->recordUrl(null)
            ->recordAction('view')
            ->contentGrid([
                'md' => 2,
                'xl' => 3,
            ])
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('piscina.name')
                            ->label('Piscina')
                            ->weight('bold')
                            ->formatStateUsing(function (DailyRecord $record): HtmlString {
                                $nome = e($record->piscina?->name);
                                if ($record->e_correcao) {
                                    $nome .= ' <span class="mmc-record-tag mmc-record-tag--warning">Correção</span>';
                                } elseif (($record->correcoes_count ?? 0) > 0) {
                                    $nome .= ' <span class="mmc-record-tag mmc-record-tag--muted">Corrigido</span>';
                                }

                                return new HtmlString($nome);
                            })
                            ->html()
                            ->description(fn (DailyRecord $record): ?string => $record->piscina?->instalacao?->name)
                            ->searchable()
                            ->sortable(),
                        Tables\Columns\TextColumn::make('registado_em')
                            ->label('Data/Hora')
                            ->dateTime('d/m/Y H:i')
                            ->color('gray')
                            ->extraAttributes(['class' => 'tabular-nums'])
                            ->sortable(),
                        Tables\Columns\TextColumn::make('utilizador.name')
                            ->label('Técnico/NS')
                            ->color('gray')
                            ->icon('heroicon-m-user')
                            ->hiddenFrom('md'),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        self::metricColumn('ph_efetivo', 'pH', fn (DailyRecord $record): bool => $record->phConforme()),
                        self::metricColumn('cloro_livre_efetivo', 'Cl. Livre', fn (DailyRecord $record): bool => $record->cloroLivreConforme()),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        self::metricColumn('cloro_total_efetivo', 'Cl. Total', null),
                        self::metricColumn('cloro_combinado', 'Cl. Comb.', fn (DailyRecord $record): bool => $record->cloroCombinadoConforme()),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('utilizador.name')
                            ->label('Técnico/NS')
                            ->color('gray')
                            ->icon('heroicon-m-user')
                            ->sortable(),
                    ])->visibleFrom('md')->space(1)->alignEnd(),
                ])->from('md')->columnSpan('full'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('pool_id')
                    ->label('Piscina')
                    ->relationship('piscina', 'name')
                    ->searchable()
                    ->preload(),
                Tables\Filters\Filter::make('periodo')
                    ->form([
                        Forms\Components\DatePicker::make('de')
                            ->label('De')
                            ->displayFormat('d/m/Y')
                            ->native(false),
                        Forms\Components\DatePicker::make('ate')
                            ->label('Até')
                            ->displayFormat('d/m/Y')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['de'] ?? null, fn (Builder $q, $de): Builder => $q->whereDate('registado_em', '>=', $de))
                            ->when($data['ate'] ?? null, fn (Builder $q, $ate): Builder => $q->whereDate('registado_em', '<=', $ate));
                    })
                    ->indicateUsing(function (array $data): array {
                        $ind = [];
                        if ($data['de'] ?? null) {
                            $ind[] = 'De '.Carbon::parse($data['de'])->format('d/m/Y');
                        }
                        if ($data['ate'] ?? null) {
                            $ind[] = 'Até '.Carbon::parse($data['ate'])->format('d/m/Y');
                        }

                        return $ind;
                    }),
                Tables\Filters\TernaryFilter::make('e_correcao')
                    ->label('Correções')
                    ->placeholder('Todos os registos')
                    ->trueLabel('Apenas correções')
                    ->falseLabel('Apenas registos originais'),
            ], layout: FiltersLayout::Modal)
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\ViewAction::make()->slideOver(),
                    Tables\Actions\Action::make('corrigir')
                        ->label('Corrigir')
                        ->icon('heroicon-o-pencil-square')
                        ->color('warning')
                        ->visible(function (DailyRecord $record): bool {
                            if ($record->e_correcao || ($record->correcoes_count ?? 0) > 0) {
                                return false;
                            }

                            $user = auth()->user();
                            if ($user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
                                return true;
                            }

                            return $user->hasRole(UserRole::NADADOR_SALVADOR) && $record->user_id === $user->id;
                        })
                        ->slideOver()
                        ->modalHeading('Corrigir registo')
                        ->modalDescription('Cria um novo registo de correção ligado ao original. O original mantém-se inalterado, como exige o livro sanitário.')
                        ->modalSubmitActionLabel('Registar correção')
                        ->fillForm(fn (DailyRecord $record): array => $record->utilizador?->hasRole(UserRole::NADADOR_SALVADOR)
                            ? [
                                'ns_ph' => $record->ns_ph,
                                'ns_cloro_livre' => $record->ns_cloro_livre,
                                'ns_cloro_total' => $record->ns_cloro_total,
                                'ns_temperatura' => $record->ns_temperatura,
                                'banhistas' => $record->banhistas,
                            ]
                            : [
                                'ph' => $record->ph,
                                'cloro_livre' => $record->cloro_livre,
                                'cloro_total' => $record->cloro_total,
                                'temperatura' => $record->temperatura,
                                'transparencia' => $record->transparencia,
                                'caleira_feita' => $record->caleira_feita,
                                'renovacao_agua' => $record->renovacao_agua,
                                'banhistas' => $record->banhistas,
                            ])
                        ->form(fn (DailyRecord $record): array => $record->utilizador?->hasRole(UserRole::NADADOR_SALVADOR)
                            ? [
                                Forms\Components\TextInput::make('ns_ph')
                                    ->label('pH')
                                    ->required()->numeric()->step(0.01)->minValue(0)->maxValue(14),
                                Forms\Components\TextInput::make('ns_cloro_livre')
                                    ->label('Cloro Livre (mg/L)')
                                    ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20),
                                Forms\Components\TextInput::make('ns_cloro_total')
                                    ->label('Cloro Total (mg/L)')
                                    ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20),
                                Forms\Components\TextInput::make('ns_temperatura')
                                    ->label('Temperatura (°C)')
                                    ->required()->numeric()->step(0.01),
                                Forms\Components\TextInput::make('banhistas')
                                    ->label('Banhistas')
                                    ->numeric()->integer()->minValue(0),
                                Forms\Components\Textarea::make('razao_correcao')
                                    ->label('Razão da correção')
                                    ->required()
                                    ->minLength(5)
                                    ->columnSpanFull(),
                            ]
                            : [
                                Forms\Components\TextInput::make('ph')
                                    ->label('pH')
                                    ->required()->numeric()->step(0.01)->minValue(0)->maxValue(14),
                                Forms\Components\TextInput::make('cloro_livre')
                                    ->label('Cloro Livre (mg/L)')
                                    ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20),
                                Forms\Components\TextInput::make('cloro_total')
                                    ->label('Cloro Total (mg/L)')
                                    ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20),
                                Forms\Components\TextInput::make('temperatura')
                                    ->label('Temperatura (°C)')
                                    ->required()->numeric()->step(0.1),
                                Forms\Components\TextInput::make('transparencia')
                                    ->label('Turbidez (FNU)')
                                    ->required()->numeric()->step(0.1)->minValue(0),
                                Forms\Components\Toggle::make('caleira_feita')
                                    ->label('Caleira feita'),
                                Forms\Components\Toggle::make('renovacao_agua')
                                    ->label('Renovação de água'),
                                Forms\Components\TextInput::make('banhistas')
                                    ->label('Banhistas')
                                    ->numeric()->integer()->minValue(0),
                                Forms\Components\Textarea::make('razao_correcao')
                                    ->label('Razão da correção')
                                    ->required()
                                    ->minLength(5)
                                    ->columnSpanFull(),
                            ])
                        ->action(function (DailyRecord $record, array $data): void {
                            $user = auth()->user();
                            $podeCorrigir = $user->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])
                                || ($user->hasRole(UserRole::NADADOR_SALVADOR) && $record->user_id === $user->id);

                            if (! $podeCorrigir) {
                                Notification::make()->danger()->title('Sem permissão')->send();

                                return;
                            }

                            $isNS = $record->utilizador?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;

                            $cloroTotalKey = $isNS ? 'ns_cloro_total' : 'cloro_total';
                            $cloroLivreKey = $isNS ? 'ns_cloro_livre' : 'cloro_livre';
                            $cloroTotal = $data[$cloroTotalKey] ?? null;
                            $cloroLivre = $data[$cloroLivreKey] ?? null;

                            if (filled($cloroTotal) && filled($cloroLivre) && (float) $cloroTotal < (float) $cloroLivre) {
                                Notification::make()
                                    ->danger()
                                    ->title('Erro na correção')
                                    ->body('O cloro total não pode ser inferior ao cloro livre.')
                                    ->send();

                                return;
                            }

                            $novoRegisto = DailyRecord::create([
                                'pool_id' => $record->pool_id,
                                'user_id' => $record->user_id,
                                'registado_em' => $record->registado_em,
                                'hora_colheita' => $record->hora_colheita,
                                'ph' => $isNS ? $record->ph : $data['ph'],
                                'cloro_livre' => $isNS ? $record->cloro_livre : $data['cloro_livre'],
                                'cloro_total' => $isNS ? $record->cloro_total : $data['cloro_total'],
                                'transparencia' => $isNS ? $record->transparencia : $data['transparencia'],
                                'temperatura' => $isNS ? $record->temperatura : $data['temperatura'],
                                'ns_ph' => $isNS ? $data['ns_ph'] : $record->ns_ph,
                                'ns_cloro_livre' => $isNS ? $data['ns_cloro_livre'] : $record->ns_cloro_livre,
                                'ns_cloro_total' => $isNS ? $data['ns_cloro_total'] : $record->ns_cloro_total,
                                'ns_temperatura' => $isNS ? $data['ns_temperatura'] : $record->ns_temperatura,
                                'caleira_feita' => $isNS ? $record->caleira_feita : $data['caleira_feita'],
                                'renovacao_agua' => $isNS ? $record->renovacao_agua : $data['renovacao_agua'],
                                'banhistas' => $data['banhistas'] ?? $record->banhistas,
                                'observacoes' => $record->observacoes,
                                'e_correcao' => true,
                                'corrige_registo_id' => $record->id,
                                'razao_correcao' => $data['razao_correcao'],
                            ]);

                            $pool = $novoRegisto->piscina;
                            $violacoes = [];
                            foreach (['ph', 'cloro_livre', 'temperatura', 'transparencia'] as $campo) {
                                $valor = $novoRegisto->{$isNS ? "ns_{$campo}" : $campo};
                                if ($valor === null) {
                                    continue;
                                }
                                $estado = DailyRecord::avaliarConformidade($isNS ? "ns_{$campo}" : $campo, $valor, $pool);
                                if ($estado['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                                    $violacoes[] = $estado['mensagem'];
                                }
                            }

                            if ($violacoes !== []) {
                                Notification::make()
                                    ->warning()
                                    ->title('Correção registada — fora de conformidade')
                                    ->body('O registo original foi mantido, mas os valores corrigidos continuam fora dos limites: '.implode('; ', $violacoes))
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->success()
                                ->title('Correção registada')
                                ->body('O registo original foi mantido e a correção ficou associada.')
                                ->send();
                        }),
                ]),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Coluna de métrica com marca de conformidade (✓/✗) ao lado do valor,
     * em vez de um veredicto agregado por linha — um pequeno desvio num
     * parâmetro não deve ler-se com a mesma força que um valor claramente fora do limite.
     */
    private static function metricColumn(string $field, string $label, ?Closure $conforme): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make($field)
            ->label($label)
            ->html()
            ->extraAttributes(['class' => 'tabular-nums text-right'])
            ->formatStateUsing(function ($state, DailyRecord $record) use ($field, $label, $conforme): HtmlString {
                if ($state === null) {
                    return new HtmlString('<span class="text-gray-400">'.$label.':</span> <span class="mmc-metric-na">—</span>');
                }

                $prefix = '<span class="text-gray-400 mr-1">'.$label.':</span>';
                $valor = e(rtrim(rtrim(number_format((float) $state, 2, ',', ''), '0'), ','));

                if ($conforme === null) {
                    return new HtmlString($prefix.' '.$valor);
                }

                $campoReal = str_replace('_efetivo', '', $field);
                $avaliacao = DailyRecord::avaliarConformidade($campoReal, $state, $record->piscina);
                $estado = $avaliacao['estado'];

                if ($estado === EstadoConformidade::VERDE) {
                    $mark = '<span class="mmc-metric-mark mmc-metric-mark--ok">✓</span>';
                } elseif ($estado === EstadoConformidade::AMARELO) {
                    $mark = '<span class="mmc-metric-mark mmc-metric-mark--warning">!</span>';
                } else {
                    $mark = '<span class="mmc-metric-mark mmc-metric-mark--bad">✗</span>';
                }

                return new HtmlString($prefix.' '.$valor.' '.$mark);
            });
    }

    private static function fotoEntry(string $field, string $label): TextEntry
    {
        return TextEntry::make($field)
            ->label($label)
            ->html()
            ->formatStateUsing(function ($state) {
                $paths = is_array($state) ? $state : [$state];
                $html = '<div class="flex flex-wrap gap-4 mt-1">';
                foreach (array_filter($paths) as $path) {
                    $url = DailyRecord::getStorageUrl($path);
                    if (! $url) {
                        continue;
                    }
                    $html .= "<a href='{$url}' class='glightbox-trigger block overflow-hidden rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 hover:ring-2 hover:ring-primary-500 hover:shadow-md transition-all duration-200'>"
                           ."<img src='{$url}' class='object-cover h-40 w-56 cursor-zoom-in' alt='Foto' />"
                           .'</a>';
                }
                $html .= '</div>';

                return $html;
            })
            ->visible(fn ($record) => filled($record?->{$field}));
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        $isSwimmerRecord = fn (?DailyRecord $record): bool => $record?->utilizador?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;

        $viewerIsNS = auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;

        return $infolist
            ->schema([
                // Swimmer (Nadador-Salvador) Simplified View
                Section::make('Registo do Nadador-Salvador')
                    ->visible(fn (?DailyRecord $record): bool => $viewerIsNS || $isSwimmerRecord($record))
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('piscina.name')
                                    ->label('Piscina'),
                                TextEntry::make('utilizador.name')
                                    ->label('Operador'),
                                TextEntry::make('registado_em')
                                    ->label('Data do Registo')
                                    ->dateTime('d/m/Y H:i'),
                            ]),
                        Section::make('Análises')
                            ->schema([
                                Grid::make(4)
                                    ->schema([
                                        TextEntry::make('ns_ph')->label('pH (NS)'),
                                        TextEntry::make('ns_cloro_livre')->label('Cloro Livre (NS)'),
                                        TextEntry::make('ns_cloro_total')->label('Cloro Total (NS)'),
                                        TextEntry::make('ns_temperatura')->label('Temperatura (NS)'),
                                        TextEntry::make('banhistas')->label('Banhistas')->placeholder('—'),
                                    ]),
                                self::fotoEntry('ns_foto', 'Foto da Análise NS'),
                            ]),
                        TextEntry::make('observacoes')
                            ->label('Observações')
                            ->visible(fn ($record) => filled($record?->observacoes)),
                    ]),

                // Full Infolist Tabs (for Technician/Admin)
                Tabs::make('Registo')
                    ->visible(fn (?DailyRecord $record): bool => ! $viewerIsNS && ! $isSwimmerRecord($record))
                    ->tabs([
                        Tab::make('Geral')
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('piscina.name')
                                            ->label('Piscina'),
                                        TextEntry::make('utilizador.name')
                                            ->label('Operador'),
                                        TextEntry::make('registado_em')
                                            ->label('Data do Registo')
                                            ->dateTime('d/m/Y H:i'),
                                        IconEntry::make('bomba_ferrada')
                                            ->label('Bomba ferrada')
                                            ->boolean(),
                                    ]),
                                self::fotoEntry('bomba_foto', 'Foto da Bomba'),
                                Grid::make(2)
                                    ->schema([
                                        TextEntry::make('contador_valor')
                                            ->label('Leitura do Contador'),
                                        TextEntry::make('agua_modo')
                                            ->label('Entrada de Água')
                                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                                'auto_com_agua' => 'Auto com água',
                                                'auto_sem_agua' => 'Auto sem água',
                                                'on_com_agua' => 'ON com água',
                                                'on_sem_agua' => 'ON sem água',
                                                'off' => 'OFF sem água',
                                                default => $state ?? '—',
                                            }),
                                    ]),
                                self::fotoEntry('contador_foto', 'Foto do Contador da Água'),
                                self::fotoEntry('torneira_foto', 'Foto da Torneira'),
                                Grid::make(2)
                                    ->schema([
                                        IconEntry::make('tanque_ok')
                                            ->label('Tanque OK')
                                            ->boolean(),
                                        TextEntry::make('tanque_observacoes')
                                            ->label('Obs. Tanque'),
                                    ]),
                                self::fotoEntry('tanque_foto', 'Foto do Tanque'),
                            ]),
                        Tab::make('Análises')
                            ->schema([
                                Section::make('Nadador-Salvador')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('ns_ph')->label('pH (NS)'),
                                                TextEntry::make('ns_cloro_livre')->label('Cloro Livre (NS)'),
                                                TextEntry::make('ns_cloro_total')->label('Cloro Total (NS)'),
                                                TextEntry::make('ns_temperatura')->label('Temperatura (NS)'),
                                                TextEntry::make('banhistas')->label('Banhistas')->placeholder('—'),
                                            ]),
                                        self::fotoEntry('ns_foto', 'Foto da Análise NS'),
                                    ]),
                                Section::make('Técnico')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('ph')->label('pH (Técnico)'),
                                                TextEntry::make('cloro_livre')->label('Cloro Livre (Técnico)'),
                                                TextEntry::make('cloro_total')->label('Cloro Total (Técnico)'),
                                                TextEntry::make('temperatura')->label('Temperatura (Técnico)'),
                                                TextEntry::make('transparencia')->label('Turbidez (FNU)'),
                                            ]),
                                        self::fotoEntry('analises_fotos', 'Fotos das Análises'),
                                    ]),
                            ]),
                        Tab::make('Filtros')
                            ->schema([
                                IconEntry::make('filtro_faz_retrolavagem')
                                    ->label('Retrolavagem Realizada')
                                    ->boolean(),
                                Grid::make(3)
                                    ->schema([
                                        self::fotoEntry('filtro_foto_retrolavagem', 'Posição Retrolavagem'),
                                        self::fotoEntry('filtro_foto_enxaguamento', 'Posição Enxaguamento'),
                                        self::fotoEntry('filtro_foto_posicao_normal', 'Posição Normal'),
                                    ]),
                            ]),
                        Tab::make('Químicos')
                            ->schema([
                                RepeatableEntry::make('adicoes')
                                    ->label('Químicos Adicionados')
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextEntry::make('product.name')->label('Produto'),
                                                TextEntry::make('quantity')->label('Quantidade'),
                                            ]),
                                        TextEntry::make('acao_corretiva')
                                            ->label('Ação corretiva')
                                            ->visible(fn ($state) => filled($state)),
                                    ]),
                                TextEntry::make('observacoes')
                                    ->label('Observações'),
                                TextEntry::make('razao_correcao')
                                    ->label('Razão da Correção')
                                    ->visible(fn ($record) => (bool) $record?->e_correcao),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
