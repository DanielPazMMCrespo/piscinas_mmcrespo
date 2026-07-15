<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource;

use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use App\Models\Pool;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyRecordTableBuilder
{
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['piscina', 'utilizador', 'adicoes.produto', 'fotos'])
                ->withCount('correcoes')
            )
            ->defaultSort('registado_em', 'desc')
            ->recordAction(Tables\Actions\ViewAction::class)
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('piscina.name')
                            ->label('Piscina')
                            ->weight('bold')
                            ->sortable()
                            ->searchable(),
                        Tables\Columns\TextColumn::make('registado_em')
                            ->label('Data/Hora')
                            ->dateTime('d/m/Y H:i')
                            ->color('gray')
                            ->size('sm')
                            ->sortable(),
                        Tables\Columns\TextColumn::make('utilizador.name')
                            ->label('Técnico/NS')
                            ->color('gray')
                            ->size('sm')
                            ->icon('heroicon-m-user')
                            ->sortable(),
                    ])->space(1),

                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('ph_efetivo')
                            ->label('pH')
                            ->formatStateUsing(fn ($state): string => 'pH '.$state)
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->phConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->phConforme() ? null : 'Fora do limite legal ('.DailyRecord::PH_MIN.'–'.DailyRecord::PH_MAX.')'),
                        Tables\Columns\TextColumn::make('cloro_livre_efetivo')
                            ->label('Cloro L.')
                            ->formatStateUsing(fn ($state): string => 'Cl '.$state.' mg/L')
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->cloroLivreConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->cloroLivreConforme() ? null : 'Fora do limite legal ('.DailyRecord::CLORO_LIVRE_MIN.'–'.DailyRecord::CLORO_LIVRE_MAX.' mg/L)'),
                        Tables\Columns\TextColumn::make('cloro_total_efetivo')
                            ->label('Cloro T.')
                            ->formatStateUsing(fn ($state): string => 'Cl.T '.$state.' mg/L')
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->cloroCombinadoConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->cloroCombinadoConforme() ? null : 'Cloro combinado acima do limite legal (máx. '.DailyRecord::getCloroCombinadoMax().' mg/L)'),
                        Tables\Columns\TextColumn::make('transparencia')
                            ->label('Turbidez')
                            ->formatStateUsing(fn ($state): string => $state.' FNU')
                            ->numeric()
                            ->badge()
                            ->color('info'),
                    ])->space(1),

                    Tables\Columns\TextColumn::make('estado')
                        ->label('Estado')
                        ->badge()
                        ->getStateUsing(function (DailyRecord $record): ?string {
                            if ($record->e_correcao) {
                                return 'Correção';
                            }
                            if (($record->correcoes_count ?? 0) > 0) {
                                return 'Corrigido';
                            }

                            return null;
                        })
                        ->color(fn (?string $state): string => $state === 'Correção' ? 'warning' : 'gray'),
                ])->from('md'),
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
                            $ind[] = 'De '.\Illuminate\Support\Carbon::parse($data['de'])->format('d/m/Y');
                        }
                        if ($data['ate'] ?? null) {
                            $ind[] = 'Até '.\Illuminate\Support\Carbon::parse($data['ate'])->format('d/m/Y');
                        }

                        return $ind;
                    }),
                Tables\Filters\TernaryFilter::make('e_correcao')
                    ->label('Correções')
                    ->placeholder('Todos os registos')
                    ->trueLabel('Apenas correções')
                    ->falseLabel('Apenas registos originais'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->extraModalFooterActions([
                        Tables\Actions\Action::make('ir_para_edicao')
                            ->label('Editar')
                            ->icon('heroicon-o-pencil')
                            ->color('gray')
                            ->url(fn (DailyRecord $record): string => Pages\EditDailyRecord::getUrl(['record' => $record])),
                    ]),
                Tables\Actions\EditAction::make(),
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
                    ->modalHeading('Corrigir registo')
                    ->modalDescription('Cria um novo registo de correção ligado ao original. O original mantém-se inalterado, como exige o livro sanitário.')
                    ->modalSubmitActionLabel('Registar correção')
                    ->fillForm(fn (DailyRecord $record): array => $record->utilizador?->hasRole(UserRole::NADADOR_SALVADOR)
                        ? [
                            'ns_ph' => $record->ns_ph,
                            'ns_cloro_livre' => $record->ns_cloro_livre,
                            'ns_cloro_total' => $record->ns_cloro_total,
                            'ns_temperatura' => $record->ns_temperatura,
                        ]
                        : [
                            'ph' => $record->ph,
                            'cloro_livre' => $record->cloro_livre,
                            'cloro_total' => $record->cloro_total,
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
                                ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20)
                                ->rules([
                                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                        if (filled($get('ns_cloro_livre')) && (float) $value < (float) $get('ns_cloro_livre')) {
                                            $fail('O cloro total não pode ser inferior ao cloro livre.');
                                        }
                                    },
                                ]),
                            Forms\Components\TextInput::make('ns_temperatura')
                                ->label('Temperatura (°C)')
                                ->required()->numeric()->step(0.01),
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
                                ->required()->numeric()->step(0.01)->minValue(0)->maxValue(20)
                                ->rules([
                                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                        if (filled($get('cloro_livre')) && (float) $value < (float) $get('cloro_livre')) {
                                            $fail('O cloro total não pode ser inferior ao cloro livre.');
                                        }
                                    },
                                ]),
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

                        DailyRecord::create([
                            'pool_id' => $record->pool_id,
                            'user_id' => auth()->id(),
                            'registado_em' => $record->registado_em,
                            'ph' => $isNS ? $record->ph : $data['ph'],
                            'cloro_livre' => $isNS ? $record->cloro_livre : $data['cloro_livre'],
                            'cloro_total' => $isNS ? $record->cloro_total : $data['cloro_total'],
                            'transparencia' => $record->transparencia,
                            'temperatura' => $record->temperatura,
                            'ns_ph' => $isNS ? $data['ns_ph'] : $record->ns_ph,
                            'ns_cloro_livre' => $isNS ? $data['ns_cloro_livre'] : $record->ns_cloro_livre,
                            'ns_cloro_total' => $isNS ? $data['ns_cloro_total'] : $record->ns_cloro_total,
                            'ns_temperatura' => $isNS ? $data['ns_temperatura'] : $record->ns_temperatura,
                            'caleira_feita' => $record->caleira_feita,
                            'renovacao_agua' => $record->renovacao_agua,
                            'observacoes' => $record->observacoes,
                            'e_correcao' => true,
                            'corrige_registo_id' => $record->id,
                            'razao_correcao' => $data['razao_correcao'],
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Correção registada')
                            ->body('O registo original foi mantido e a correção ficou associada.')
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function fotoEntry(string $field, string $label): \Filament\Infolists\Components\TextEntry
    {
        return \Filament\Infolists\Components\TextEntry::make($field)
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
                           . "<img src='{$url}' class='object-cover h-40 w-56 cursor-zoom-in' alt='Foto' />"
                           . '</a>';
                }
                $html .= '</div>';
                return $html;
            })
            ->visible(fn ($record) => filled($record?->{$field}));
    }

    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        $isSwimmerRecord = fn (?DailyRecord $record): bool =>
            $record?->utilizador?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;

        $viewerIsNS = auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR) ?? false;

        return $infolist
            ->schema([
                // Swimmer (Nadador-Salvador) Simplified View
                \Filament\Infolists\Components\Section::make('Registo do Nadador-Salvador')
                    ->visible(fn (?DailyRecord $record): bool => $viewerIsNS || $isSwimmerRecord($record))
                    ->schema([
                        \Filament\Infolists\Components\Grid::make(3)
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('pool.name')
                                    ->label('Piscina'),
                                \Filament\Infolists\Components\TextEntry::make('user.name')
                                    ->label('Operador'),
                                \Filament\Infolists\Components\TextEntry::make('registado_em')
                                    ->label('Data do Registo')
                                    ->dateTime('d/m/Y H:i'),
                            ]),
                        \Filament\Infolists\Components\Section::make('Análises')
                            ->schema([
                                \Filament\Infolists\Components\Grid::make(4)
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('ns_ph')->label('pH (NS)'),
                                        \Filament\Infolists\Components\TextEntry::make('ns_cloro_livre')->label('Cloro Livre (NS)'),
                                        \Filament\Infolists\Components\TextEntry::make('ns_cloro_total')->label('Cloro Total (NS)'),
                                        \Filament\Infolists\Components\TextEntry::make('ns_temperatura')->label('Temperatura (NS)'),
                                    ]),
                                self::fotoEntry('ns_foto', 'Foto da Análise NS'),
                            ]),
                        \Filament\Infolists\Components\TextEntry::make('observacoes')
                            ->label('Observações')
                            ->visible(fn ($record) => filled($record?->observacoes)),
                    ]),

                // Full Infolist Tabs (for Technician/Admin)
                \Filament\Infolists\Components\Tabs::make('Registo')
                    ->visible(fn (?DailyRecord $record): bool => !$viewerIsNS && !$isSwimmerRecord($record))
                    ->tabs([
                        \Filament\Infolists\Components\Tabs\Tab::make('Geral')
                            ->schema([
                                \Filament\Infolists\Components\Grid::make(2)
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('pool.name')
                                            ->label('Piscina'),
                                        \Filament\Infolists\Components\TextEntry::make('user.name')
                                            ->label('Operador'),
                                        \Filament\Infolists\Components\TextEntry::make('registado_em')
                                            ->label('Data do Registo')
                                            ->dateTime('d/m/Y H:i'),
                                        \Filament\Infolists\Components\IconEntry::make('bomba_ferrada')
                                            ->label('Bomba ferrada')
                                            ->boolean(),
                                    ]),
                                self::fotoEntry('bomba_foto', 'Foto da Bomba'),
                                \Filament\Infolists\Components\Grid::make(2)
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('contador_valor')
                                            ->label('Leitura do Contador'),
                                        \Filament\Infolists\Components\TextEntry::make('agua_modo')
                                            ->label('Entrada de Água'),
                                    ]),
                                self::fotoEntry('contador_foto', 'Foto do Contador'),
                                \Filament\Infolists\Components\Grid::make(2)
                                    ->schema([
                                        \Filament\Infolists\Components\IconEntry::make('tanque_ok')
                                            ->label('Tanque OK')
                                            ->boolean(),
                                        \Filament\Infolists\Components\TextEntry::make('tanque_observacoes')
                                            ->label('Obs. Tanque'),
                                    ]),
                                self::fotoEntry('tanque_foto', 'Foto do Tanque'),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Análises')
                            ->schema([
                                \Filament\Infolists\Components\Section::make('Nadador-Salvador')
                                    ->schema([
                                        \Filament\Infolists\Components\Grid::make(2)
                                            ->schema([
                                                \Filament\Infolists\Components\TextEntry::make('ns_ph')->label('pH (NS)'),
                                                \Filament\Infolists\Components\TextEntry::make('ns_cloro_livre')->label('Cloro Livre (NS)'),
                                                \Filament\Infolists\Components\TextEntry::make('ns_cloro_total')->label('Cloro Total (NS)'),
                                                \Filament\Infolists\Components\TextEntry::make('ns_temperatura')->label('Temperatura (NS)'),
                                            ]),
                                        self::fotoEntry('ns_foto', 'Foto da Análise NS'),
                                    ]),
                                \Filament\Infolists\Components\Section::make('Técnico')
                                    ->schema([
                                        \Filament\Infolists\Components\Grid::make(2)
                                            ->schema([
                                                \Filament\Infolists\Components\TextEntry::make('ph')->label('pH (Técnico)'),
                                                \Filament\Infolists\Components\TextEntry::make('cloro_livre')->label('Cloro Livre (Técnico)'),
                                                \Filament\Infolists\Components\TextEntry::make('cloro_total')->label('Cloro Total (Técnico)'),
                                                \Filament\Infolists\Components\TextEntry::make('temperatura')->label('Temperatura (Técnico)'),
                                                \Filament\Infolists\Components\TextEntry::make('transparencia')->label('Turbidez (FNU)'),
                                            ]),
                                        self::fotoEntry('analises_fotos', 'Fotos das Análises'),
                                    ]),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Filtros')
                            ->schema([
                                \Filament\Infolists\Components\IconEntry::make('filtro_faz_retrolavagem')
                                    ->label('Retrolavagem Realizada')
                                    ->boolean(),
                                \Filament\Infolists\Components\Grid::make(3)
                                    ->schema([
                                        self::fotoEntry('filtro_foto_retrolavagem', 'Posição Retrolavagem'),
                                        self::fotoEntry('filtro_foto_enxaguamento', 'Posição Enxaguamento'),
                                        self::fotoEntry('filtro_foto_posicao_normal', 'Posição Normal'),
                                    ]),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Químicos')
                            ->schema([
                                \Filament\Infolists\Components\RepeatableEntry::make('adicoes')
                                    ->label('Químicos Adicionados')
                                    ->schema([
                                        \Filament\Infolists\Components\Grid::make(2)
                                            ->schema([
                                                \Filament\Infolists\Components\TextEntry::make('product.name')->label('Produto'),
                                                \Filament\Infolists\Components\TextEntry::make('quantity')->label('Quantidade'),
                                            ]),
                                    ]),
                                \Filament\Infolists\Components\TextEntry::make('observacoes')
                                    ->label('Observações'),
                                \Filament\Infolists\Components\TextEntry::make('razao_correcao')
                                    ->label('Razão da Correção')
                                    ->visible(fn ($record) => (bool)$record?->e_correcao),
                            ]),
                    ])
                    ->columnSpanFull()
            ]);
    }

}
