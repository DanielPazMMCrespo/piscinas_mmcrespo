<?php

declare(strict_types=1);

namespace App\Filament\Resources\PoolClosureResource\RelationManagers;

use App\Constants\TrabalhoParagem;
use App\Models\DailyRecord;
use App\Models\OperationalAction;
use App\Models\PoolClosure;
use App\Models\PoolClosureTask;
use App\Models\User;
use App\Services\EvidenciaParagemService;
use App\Services\PlanoParagemService;
use DomainException;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TrabalhosRelationManager extends RelationManager
{
    protected static string $relationship = 'trabalhos';

    protected static ?string $title = 'Plano de Trabalhos de Paragem Técnica';

    protected static ?string $recordTitleAttribute = 'tipo';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('previsto_para')
                ->label('Previsto para')
                ->native(false),
            Forms\Components\Textarea::make('observacoes')
                ->label('Observações')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('tipo')
            ->defaultSort('ordem')
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('tipo')
                    ->label('Trabalho')
                    ->weight('semibold')
                    ->formatStateUsing(fn (PoolClosureTask $record): string => $record->tipoLabel())
                    ->description(fn (PoolClosureTask $record): ?string => $record->dadosFormatados() !== '—' ? $record->dadosFormatados() : null)
                    ->wrap(),
                Tables\Columns\IconColumn::make('obrigatorio')
                    ->label('Obrigatório')
                    ->boolean()
                    ->trueIcon('heroicon-o-shield-check')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('danger')
                    ->falseColor('gray'),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TrabalhoParagem::estadoLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        TrabalhoParagem::ESTADO_EXECUTADO => 'success',
                        TrabalhoParagem::ESTADO_EM_CURSO => 'warning',
                        TrabalhoParagem::ESTADO_NAO_APLICAVEL => 'gray',
                        TrabalhoParagem::ESTADO_NAO_EXECUTADO => 'danger',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('previsto_para')
                    ->label('Previsto para')
                    ->date('d/m/Y')
                    ->placeholder('A definir')
                    ->sortable(),
                Tables\Columns\TextColumn::make('executado_em')
                    ->label('Executado em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('executadoPor.name')
                    ->label('Executado por')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('motivo_nao_execucao')
                    ->label('Justificação / Motivo')
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('origem')
                    ->label('Origem')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TrabalhoParagem::origemLabel($state))
                    ->color(fn (string $state): string => match ($state) {
                        TrabalhoParagem::ORIGEM_DECLARADA => 'gray',
                        TrabalhoParagem::ORIGEM_RECONSTRUIDA => 'warning',
                        TrabalhoParagem::ORIGEM_INFERIDA => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\Action::make('gerarPlano')
                    ->label('Gerar Plano de Trabalhos')
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(function (): bool {
                        /** @var PoolClosure $owner */
                        $owner = $this->getOwnerRecord();

                        return $owner->trabalhos()->doesntExist();
                    })
                    ->action(function (): void {
                        /** @var PoolClosure $owner */
                        $owner = $this->getOwnerRecord();
                        $user = auth()->user();

                        if ($user === null) {
                            return;
                        }

                        try {
                            app(PlanoParagemService::class)->criarPlano($owner, $user);
                            Notification::make()
                                ->success()
                                ->title('Plano de trabalhos criado')
                                ->body('O template legal com as 13 tarefas canónicas foi gerado com sucesso.')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Erro ao criar plano')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\CreateAction::make('acrescentarTrabalho')
                    ->label('Acrescentar Trabalho')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Acrescentar Trabalho ao Plano')
                    ->form([
                        Forms\Components\Select::make('tipo')
                            ->label('Tipo de Trabalho')
                            ->options(TrabalhoParagem::labels())
                            ->default(TrabalhoParagem::OUTRO)
                            ->required()
                            ->native(false),
                        Forms\Components\DatePicker::make('previsto_para')
                            ->label('Previsto para')
                            ->native(false),
                        Forms\Components\Toggle::make('obrigatorio')
                            ->label('Obrigatório por regulamento ou decisão técnica')
                            ->default(false),
                        Forms\Components\Textarea::make('observacoes')
                            ->label('Observações / Descrição do Trabalho')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->using(function (array $data): PoolClosureTask {
                        /** @var PoolClosure $owner */
                        $owner = $this->getOwnerRecord();
                        $user = auth()->user();

                        if ($user === null) {
                            throw new DomainException('Utilizador não autenticado.');
                        }

                        return app(PlanoParagemService::class)->acrescentarTrabalho($owner, $data, $user);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('marcarExecutado')
                    ->label('Executar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PoolClosureTask $record): bool => $record->estado !== TrabalhoParagem::ESTADO_EXECUTADO)
                    ->modalHeading(fn (PoolClosureTask $record): string => "Registar Execução: {$record->tipoLabel()}")
                    ->form(fn (PoolClosureTask $record): array => self::formularioExecucao($record))
                    ->action(function (PoolClosureTask $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null) {
                            return;
                        }

                        try {
                            app(PlanoParagemService::class)->marcarExecutado($record, $data, $user);
                            Notification::make()
                                ->success()
                                ->title("{$record->tipoLabel()} marcado como executado")
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Erro de validação legal')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('marcarNaoExecutado')
                    ->label('Não Executado')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PoolClosureTask $record): bool => $record->estado !== TrabalhoParagem::ESTADO_NAO_EXECUTADO)
                    ->modalHeading(fn (PoolClosureTask $record): string => "Justificar Não Execução: {$record->tipoLabel()}")
                    ->form([
                        Forms\Components\Textarea::make('motivo_nao_execucao')
                            ->label('Motivo da Não Execução')
                            ->required()
                            ->rows(3)
                            ->placeholder('Descreva detalhadamente o motivo pelo qual este trabalho não foi executado no período.'),
                    ])
                    ->action(function (PoolClosureTask $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null) {
                            return;
                        }

                        try {
                            app(PlanoParagemService::class)->marcarNaoExecutado($record, (string) $data['motivo_nao_execucao'], $user);
                            Notification::make()
                                ->success()
                                ->title('Trabalho marcado como não executado')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Erro')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('marcarNaoAplicavel')
                    ->label('Não Aplicável')
                    ->icon('heroicon-o-minus-circle')
                    ->color('gray')
                    ->visible(fn (PoolClosureTask $record): bool => $record->estado !== TrabalhoParagem::ESTADO_NAO_APLICAVEL)
                    ->modalHeading(fn (PoolClosureTask $record): string => "Fundamentar Não Aplicabilidade: {$record->tipoLabel()}")
                    ->form([
                        Forms\Components\Textarea::make('motivo_nao_execucao')
                            ->label('Fundamentação Técnica de Não Aplicabilidade')
                            ->required()
                            ->rows(3)
                            ->placeholder('Ex: Piscina do tipo skimmers sem tanque de compensação instalado.'),
                    ])
                    ->action(function (PoolClosureTask $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null) {
                            return;
                        }

                        try {
                            app(PlanoParagemService::class)->marcarNaoAplicavel($record, (string) $data['motivo_nao_execucao'], $user);
                            Notification::make()
                                ->success()
                                ->title('Trabalho marcado como não aplicável')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Erro')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('reconstruirEvidencia')
                    ->label('Sugerir Evidência')
                    ->icon('heroicon-o-light-bulb')
                    ->color('warning')
                    ->visible(fn (PoolClosureTask $record): bool => $record->estado === TrabalhoParagem::ESTADO_PREVISTO)
                    ->modalHeading(fn (PoolClosureTask $record): string => "Reconciliar Evidência: {$record->tipoLabel()}")
                    ->modalDescription('Pré-preencha a execução a partir de ações operacionais registadas no período ou deteção de sonda. O registo será gravado com proveniência "reconstruída".')
                    ->form(function (PoolClosureTask $record): array {
                        /** @var PoolClosure|null $encerramento */
                        $encerramento = $record->encerramento;
                        $tiposAcao = TrabalhoParagem::acoesOperacionaisCompativeis($record->tipo);

                        $opcoesAcoes = [];
                        if (! empty($tiposAcao) && $encerramento !== null) {
                            $de = $encerramento->inicio->copy()->startOfDay();
                            $ate = ($encerramento->fim ?? now())->copy()->endOfDay();

                            $acoes = OperationalAction::query()
                                ->where('pool_id', $encerramento->pool_id)
                                ->whereIn('tipo', $tiposAcao)
                                ->whereBetween('registado_em', [$de, $ate])
                                ->with('utilizador')
                                ->orderBy('registado_em')
                                ->get();

                            foreach ($acoes as $acao) {
                                $dataStr = $acao->registado_em->format('d/m/Y H:i');
                                $autorNome = $acao->utilizador instanceof User ? $acao->utilizador->name : 'Técnico';
                                $opcoesAcoes["acao_{$acao->id}"] = "Ação Operacional: {$acao->tipoLabel()} a {$dataStr} ({$autorNome}) — {$acao->dadosFormatados()}";
                            }
                        }

                        $opcoesSonda = [];
                        if ($encerramento !== null) {
                            $candidatos = app(EvidenciaParagemService::class)->candidatosPara($encerramento, $record->tipo);
                            foreach ($candidatos as $idx => $cand) {
                                $dataStr = $cand['momento']->format('d/m/Y H:i');
                                $opcoesSonda["sonda_{$idx}"] = "Sonda Hanna ({$cand['confianca']}): {$cand['detalhe']} em {$dataStr}";
                            }
                        }

                        $todasOpcoes = array_merge($opcoesAcoes, $opcoesSonda);

                        if (empty($todasOpcoes)) {
                            return [
                                Forms\Components\Placeholder::make('sem_evidencias')
                                    ->label('Sem evidências automáticas')
                                    ->content('Não foram encontradas ações operacionais compatíveis nem padrões de sonda para este trabalho no período.'),
                            ];
                        }

                        return [
                            Forms\Components\Radio::make('evidencia_selecionada')
                                ->label('Evidência Encontrada no Período')
                                ->options($todasOpcoes)
                                ->required(),
                            Forms\Components\DateTimePicker::make('executado_em')
                                ->label('Data e Hora de Execução Validada')
                                ->default(now())
                                ->required()
                                ->native(false),
                            Forms\Components\Textarea::make('observacoes')
                                ->label('Observações de Reconstrução')
                                ->default('Reconstruído a partir de evidência no período da paragem técnica.')
                                ->rows(2),
                        ];
                    })
                    ->action(function (PoolClosureTask $record, array $data): void {
                        $user = auth()->user();
                        if ($user === null) {
                            return;
                        }

                        $dadosExecucao = [
                            'executado_em' => $data['executado_em'] ?? now(),
                            'observacoes' => $data['observacoes'] ?? null,
                            'origem' => TrabalhoParagem::ORIGEM_RECONSTRUIDA,
                        ];

                        try {
                            app(PlanoParagemService::class)->marcarExecutado($record, $dadosExecucao, $user);
                            Notification::make()
                                ->success()
                                ->title('Trabalho reconstruído e validado com sucesso')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Erro')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),

                Tables\Actions\EditAction::make('editarData')
                    ->label('Editar')
                    ->icon('heroicon-m-pencil-square')
                    ->color('gray'),

                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('Sem plano de trabalhos gerado')
            ->emptyStateDescription('Clique em "Gerar Plano de Trabalhos" para criar a checklist com as 13 tarefas legais.');
    }

    /**
     * Constrói o formulário de execução especializado por tipo de trabalho.
     *
     * @return array<int, Forms\Components\Component>
     */
    private static function formularioExecucao(PoolClosureTask $record): array
    {
        $campos = [
            Forms\Components\DateTimePicker::make('executado_em')
                ->label('Data e Hora da Execução')
                ->default(now())
                ->required()
                ->native(false),
        ];

        switch ($record->tipo) {
            case TrabalhoParagem::SUPERCLORACAO:
                $campos[] = Forms\Components\Section::make('Parâmetros da Supercloração')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('dados.cloro_livre_atingido')
                            ->label('Cloro Livre Atingido (mg/L)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.tempo_contacto_horas')
                            ->label('Tempo de Contacto (horas)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.ph_durante_choque')
                            ->label('pH durante o Choque')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.neutralizacao_quimica')
                            ->label('Neutralização / Decloração (se aplicável)')
                            ->placeholder('Ex: Tiosulfato de sódio / Peróxido de hidrogénio'),
                    ])->columns(2);
                break;

            case TrabalhoParagem::LIMPEZA_TANQUE:
            case TrabalhoParagem::LIMPEZA_TANQUE_COMPENSACAO:
            case TrabalhoParagem::LIMPEZA_CALEIRAS:
            case TrabalhoParagem::LIMPEZA_CIRCUITO:
                // O Anexo A exige "limpeza E desinfecção" — sem produto e
                // concentração registados, o relatório não prova a desinfeção.
                $campos[] = Forms\Components\Section::make('Limpeza e Desinfeção')
                    ->collapsed()
                    ->description('O caderno de encargos exige desinfeção, não só limpeza: registe o produto e a concentração.')
                    ->schema([
                        Forms\Components\TextInput::make('dados.produto')
                            ->label('Produto Utilizado')
                            ->placeholder('Ex: Hipoclorito de Sódio'),
                        Forms\Components\TextInput::make('dados.concentracao')
                            ->label('Concentração / Dose')
                            ->placeholder('Ex: 200 mg/L'),
                        // O valor guardado é o próprio rótulo: `dados` é JSON
                        // livre e o PDF imprime-o tal e qual, sem tradução.
                        Forms\Components\Select::make('dados.metodo')
                            ->label('Método')
                            ->options([
                                'Escovagem manual' => 'Escovagem manual',
                                'Hidropressão / jato' => 'Hidropressão / jato',
                                'Escovagem + hidropressão' => 'Escovagem + hidropressão',
                                'Outro (ver observações)' => 'Outro (ver observações)',
                            ])
                            ->native(false),
                        Forms\Components\TextInput::make('dados.tempo_contacto_min')
                            ->label('Tempo de Contacto (min)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'numeric']),
                        Forms\Components\Toggle::make('dados.enxaguado')
                            ->label('Enxaguado após desinfeção')
                            ->default(true)
                            ->columnSpanFull(),
                    ])->columns(2);
                break;

            case TrabalhoParagem::REPOSICAO_CLORO:
                // O vereador perguntou pelo valor reposto. Sem antes/depois o
                // relatório diz que foi feito, mas não a que nível ficou.
                $campos[] = Forms\Components\Section::make('Reposição de Cloro e pH')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('dados.cloro_antes')
                            ->label('Cloro Livre Antes (mg/L)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.cloro_depois')
                            ->label('Cloro Livre Depois (mg/L)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.ph_depois')
                            ->label('pH Depois')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.produto')
                            ->label('Produto Utilizado')
                            ->placeholder('Ex: Hipoclorito de Sódio')
                            ->columnSpanFull(),
                    ])->columns(3);
                break;

            case TrabalhoParagem::ESVAZIAMENTO_TANQUE:
            case TrabalhoParagem::ENCHIMENTO_TANQUE:
                $campos[] = Forms\Components\Section::make('Volume e Contador')
                    ->collapsed()
                    ->description('A renovação de água é registada por leitura de contador (Anexo A).')
                    ->schema([
                        Forms\Components\TextInput::make('dados.contador_inicio')
                            ->label('Contador ao Início (m³)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.contador_fim')
                            ->label('Contador ao Fim (m³)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.volume_m3')
                            ->label('Volume (m³)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                    ])->columns(3);
                break;

            case TrabalhoParagem::DESINFECAO_LEGIONELLA:
                $campos[] = Forms\Components\Section::make('Boletim Analítico de Legionella')
                    ->collapsed()
                    ->description('Obrigatório por lei: Carregue o boletim emitido por laboratório acreditado.')
                    ->schema([
                        Forms\Components\TextInput::make('dados.laboratorio')
                            ->label('Laboratório Acreditado')
                            ->placeholder('Ex: LabQualidade / INSA'),
                        Forms\Components\TextInput::make('dados.numero_boletim')
                            ->label('N.º do Boletim Analítico'),
                        Forms\Components\TextInput::make('dados.resultado_legionella')
                            ->label('Resultado (UFC/L)')
                            ->placeholder('Ex: < 100 UFC/L (Não detetado)'),
                        Forms\Components\CheckboxList::make('dados.zonas_desinfetadas')
                            ->label('Zonas / Circuitos Desinfetados')
                            ->options([
                                'tanque_principal' => 'Tanque Principal',
                                'tanque_compensacao' => 'Tanque de Compensação',
                                'chuveiros_balnearios' => 'Rede de Chuveiros / Balneários',
                                'rede_aqs' => 'Circuito AQS / Depósitos',
                                'circuitos_filtracao' => 'Circuitos de Filtração / UTA',
                            ])
                            ->columns(2),
                    ]);
                break;

            case TrabalhoParagem::MANUTENCAO_FILTROS:
                $campos[] = Forms\Components\Section::make('Intervenção nos Filtros')
                    ->collapsed()
                    ->schema([
                        Forms\Components\CheckboxList::make('dados.filtros_intervencionados')
                            ->label('Filtros Intervencionados')
                            ->options([
                                'filtro_1' => 'Filtro 1',
                                'filtro_2' => 'Filtro 2',
                                'filtro_3' => 'Filtro 3',
                                'filtro_4' => 'Filtro 4',
                                'filtro_5' => 'Filtro 5',
                                'filtro_6' => 'Filtro 6',
                            ])
                            ->columns(3),
                        Forms\Components\Select::make('dados.tipo_intervencao')
                            ->label('Tipo de Intervenção')
                            ->options([
                                'lavagem_quimica' => 'Lavagem química / desincrustação',
                                'substituicao_areia' => 'Substituição integral da massa filtrante',
                                'reposicao_areia' => 'Reposição de nível de massa filtrante',
                                'inspecao_crepinas' => 'Inspeção de crepinas e coletores',
                            ])
                            ->native(false),
                    ]);
                break;

            case TrabalhoParagem::ARRANQUE_AQUECIMENTO:
                $campos[] = Forms\Components\Section::make('Aquecimento da Água')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('dados.temperatura_inicial')
                            ->label('Temp. Inicial (°C)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.temperatura_alvo')
                            ->label('Temp. Alvo (°C)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.equipamentos')
                            ->label('Equipamentos Ligados')
                            ->placeholder('Ex: Caldeiras 1 e 2, Permutador 3')
                            ->columnSpanFull(),
                    ])->columns(2);
                break;

            case TrabalhoParagem::VERIFICACAO_PARAMETROS:
                $campos[] = Forms\Components\Section::make('Parâmetros Químicos Pré-Reabertura')
                    ->collapsed()
                    ->schema([
                        Forms\Components\TextInput::make('dados.ph')
                            ->label('pH')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.cloro_livre')
                            ->label('Cloro Livre (mg/L)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.cloro_total')
                            ->label('Cloro Total (mg/L)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.temperatura_agua')
                            ->label('Temp. Água (°C)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.turbidez')
                            ->label('Turvação (NTU)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                        Forms\Components\TextInput::make('dados.acido_cianurico')
                            ->label('Ácido Cianúrico (mg/L)')
                            ->numeric()
                            ->extraInputAttributes(['inputmode' => 'decimal']),
                    ])->columns(3);
                break;
        }

        $campos[] = Forms\Components\FileUpload::make('fotos')
            ->label('Fotografias de Evidência')
            ->disk(DailyRecord::getStorageDisk())
            ->visibility('private')
            ->directory('paragens')
            ->multiple()
            ->maxFiles(10)
            ->maxSize(20480)
            ->image()
            // Sem HEIC no accept, o iOS converte a foto para JPEG na seleção.
            // O dompdf não descodifica HEIC: aceitá-lo aqui empurrava as fotos
            // do iPhone para a lista de "não impressas" do relatório legal.
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->helperText('As fotos do iPhone são convertidas para JPEG automaticamente, para poderem ser impressas no relatório.')
            ->columnSpanFull();

        // O boletim analitico so faz sentido na desinfecao de Legionella; nos
        // restantes trabalhos o campo so acrescentava ruido ao formulario.
        if ($record->tipo === TrabalhoParagem::DESINFECAO_LEGIONELLA) {
            $campos[] = Forms\Components\FileUpload::make('documentos')
                ->label('Boletim Analítico (PDF)')
                ->helperText('A lei exige o PDF do boletim acreditado (pode anexar mais tarde para fechar o trabalho no terreno).')
                ->disk(DailyRecord::getStorageDisk())
                ->visibility('private')
                ->directory('paragens')
                ->multiple()
                ->maxFiles(10)
                ->maxSize(20480)
                ->acceptedFileTypes(['application/pdf'])
                ->columnSpanFull();
        }

        $campos[] = Forms\Components\Textarea::make('observacoes')
            ->label('Observações Adicionais')
            ->rows(2)
            ->columnSpanFull();

        return $campos;
    }
}
