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
            ->recordUrl(fn (DailyRecord $record): string => Pages\EditDailyRecord::getUrl(['record' => $record]))
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
                        Tables\Columns\TextColumn::make('ph')
                            ->label('pH')
                            ->formatStateUsing(fn ($state): string => 'pH '.$state)
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->phConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->phConforme() ? null : 'Fora do limite legal ('.DailyRecord::PH_MIN.'–'.DailyRecord::PH_MAX.')'),
                        Tables\Columns\TextColumn::make('cloro_livre')
                            ->label('Cloro L.')
                            ->formatStateUsing(fn ($state): string => 'Cl '.$state.' mg/L')
                            ->numeric()
                            ->badge()
                            ->color(fn (DailyRecord $record): string => $record->cloroLivreConforme() ? 'success' : 'danger')
                            ->tooltip(fn (DailyRecord $record): ?string => $record->cloroLivreConforme() ? null : 'Fora do limite legal ('.DailyRecord::CLORO_LIVRE_MIN.'–'.DailyRecord::CLORO_LIVRE_MAX.' mg/L)'),
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('corrigir')
                    ->label('Corrigir')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (DailyRecord $record): bool => ! $record->e_correcao && ($record->correcoes_count ?? 0) === 0 && auth()->user()->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO]))
                    ->modalHeading('Corrigir registo')
                    ->modalDescription('Cria um novo registo de correção ligado ao original. O original mantém-se inalterado, como exige o livro sanitário.')
                    ->modalSubmitActionLabel('Registar correção')
                    ->fillForm(fn (DailyRecord $record): array => [
                        'ph' => $record->ph,
                        'cloro_livre' => $record->cloro_livre,
                        'cloro_total' => $record->cloro_total,
                        'transparencia' => $record->transparencia,
                    ])
                    ->form([
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
                        Forms\Components\TextInput::make('transparencia')
                            ->label('Turbidez (FNU)')
                            ->required()->numeric()->step(0.01)->minValue(0)->maxValue(DailyRecord::TRANSPARENCIA_MAX),
                        Forms\Components\Textarea::make('razao_correcao')
                            ->label('Razão da correção')
                            ->required()
                            ->minLength(5)
                            ->columnSpanFull(),
                    ])
                    ->action(function (DailyRecord $record, array $data): void {
                        if (! auth()->user()->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
                            Notification::make()->danger()->title('Sem permissão')->send();
                            return;
                        }

                        DailyRecord::create([
                            'pool_id' => $record->pool_id,
                            'user_id' => auth()->id(),
                            'registado_em' => $record->registado_em,
                            'ph' => $data['ph'],
                            'cloro_livre' => $data['cloro_livre'],
                            'cloro_total' => $data['cloro_total'],
                            'transparencia' => $data['transparencia'],
                            'temperatura' => $record->temperatura,
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

    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist
            ->schema([
                \Filament\Infolists\Components\Tabs::make('Registo')
                    ->tabs([
                        \Filament\Infolists\Components\Tabs\Tab::make('Piscina & Estado')
                            ->icon('heroicon-o-home')
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
                                \Filament\Infolists\Components\ImageEntry::make('bomba_foto')
                                    ->label('Foto da Bomba')
                                    ->disk(DailyRecord::getStorageDisk())
                                    ->visibility('public')
                                    ->visible(fn ($record) => filled($record?->bomba_foto)),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Contador & Água')
                            ->icon('heroicon-o-calculator')
                            ->schema([
                                \Filament\Infolists\Components\Grid::make(2)
                                    ->schema([
                                        \Filament\Infolists\Components\TextEntry::make('contador_valor')
                                            ->label('Leitura do Contador'),
                                        \Filament\Infolists\Components\TextEntry::make('agua_modo')
                                            ->label('Entrada de Água'),
                                    ]),
                                \Filament\Infolists\Components\ImageEntry::make('contador_foto')
                                    ->label('Foto do Contador')
                                    ->disk(DailyRecord::getStorageDisk())
                                    ->visibility('public')
                                    ->visible(fn ($record) => filled($record?->contador_foto)),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Tanque de Compensação')
                            ->icon('heroicon-o-beaker')
                            ->schema([
                                \Filament\Infolists\Components\Grid::make(2)
                                    ->schema([
                                        \Filament\Infolists\Components\IconEntry::make('tanque_ok')
                                            ->label('Tanque OK')
                                            ->boolean(),
                                        \Filament\Infolists\Components\TextEntry::make('tanque_observacoes')
                                            ->label('Observações'),
                                    ]),
                                \Filament\Infolists\Components\ImageEntry::make('tanque_foto')
                                    ->label('Foto do Tanque')
                                    ->disk(DailyRecord::getStorageDisk())
                                    ->visibility('public')
                                    ->visible(fn ($record) => filled($record?->tanque_foto)),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Análises')
                            ->icon('heroicon-o-eye')
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
                                        \Filament\Infolists\Components\ImageEntry::make('ns_foto')
                                            ->label('Foto da Análise NS')
                                            ->disk(DailyRecord::getStorageDisk())
                                            ->visibility('public')
                                            ->visible(fn ($record) => filled($record?->ns_foto)),
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
                                        \Filament\Infolists\Components\ImageEntry::make('analises_fotos')
                                            ->label('Fotos das Análises')
                                            ->disk(DailyRecord::getStorageDisk())
                                            ->visibility('public')
                                            ->multiple()
                                            ->visible(fn ($record) => !empty($record?->analises_fotos)),
                                    ]),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Filtros')
                            ->icon('heroicon-o-funnel')
                            ->schema([
                                \Filament\Infolists\Components\IconEntry::make('filtro_faz_retrolavagem')
                                    ->label('Retrolavagem Realizada')
                                    ->boolean(),
                                \Filament\Infolists\Components\Grid::make(3)
                                    ->schema([
                                        \Filament\Infolists\Components\ImageEntry::make('filtro_foto_retrolavagem')
                                            ->label('Posição Retrolavagem')
                                            ->disk(DailyRecord::getStorageDisk())
                                            ->visibility('public')
                                            ->visible(fn ($record) => filled($record?->filtro_foto_retrolavagem)),
                                        \Filament\Infolists\Components\ImageEntry::make('filtro_foto_enxaguamento')
                                            ->label('Posição Enxaguamento')
                                            ->disk(DailyRecord::getStorageDisk())
                                            ->visibility('public')
                                            ->visible(fn ($record) => filled($record?->filtro_foto_enxaguamento)),
                                        \Filament\Infolists\Components\ImageEntry::make('filtro_foto_posicao_normal')
                                            ->label('Posição Normal')
                                            ->disk(DailyRecord::getStorageDisk())
                                            ->visibility('public')
                                            ->visible(fn ($record) => filled($record?->filtro_foto_posicao_normal)),
                                    ]),
                            ]),
                        \Filament\Infolists\Components\Tabs\Tab::make('Químicos & Notas')
                            ->icon('heroicon-o-sparkles')
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
