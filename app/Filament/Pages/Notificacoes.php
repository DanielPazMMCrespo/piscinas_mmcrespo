<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\CustomBroadcast;
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

/**
 * Ativação de notificações push neste dispositivo, zona de testes e envio de
 * avisos globais (Notificações Personalizadas).
 */
class Notificacoes extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Notificações';

    protected static ?string $title = 'Notificações';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.notificacoes';

    public string $destinoTipo = 'cargo';
    public string $destinoCargo = 'admin';
    public ?int $destinoUtilizador = null;
    public string $manualTitulo = '';
    public string $manualCorpo = '';
    
    public ?array $preferencesData = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    protected function getForms(): array
    {
        return [
            'preferencesForm',
        ];
    }

    public function preferencesForm(Forms\Form $form): Forms\Form
    {
        $schema = [
            Forms\Components\Section::make('Preferências Globais de Notificação')
                ->description('Personalize exatamente quais notificações deseja receber por Push (no dispositivo/browser) e por E-mail.')
                ->schema([
                    // 1. Incidentes
                    Forms\Components\Section::make('Incidentes e Ocorrências')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->schema([
                            $this->getSingleNotificationItemSchema('Novo Incidente', 'incident_created', 'Receber aviso quando um novo incidente é reportado.'),
                            $this->getSingleNotificationItemSchema('Mensagens em Incidentes', 'incident_message', 'Notificações de novas mensagens e respostas no chat de um incidente.'),
                        ])
                        ->collapsible(),

                    // 2. Operação
                    Forms\Components\Section::make('Operação e Casa das Máquinas')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->schema([
                            $this->getSingleNotificationItemSchema('Fim de Temporizador', 'timer_finished', 'Alerta quando o temporizador da retrolavagem/enxaguamento chega ao fim.'),
                            $this->getSingleNotificationItemSchema('Torneira Aberta', 'torneira_aberta', 'Alerta quando uma torneira de reposição se mantém aberta além do limite.'),
                            $this->getSingleNotificationItemSchema('Nível Baixo nos Bidões', 'dosing_low', 'Aviso quando o nível estimado de produto químico no bidão está baixo.'),
                        ])
                        ->collapsible(),

                    // 3. Conformidade & Sensores
                    Forms\Components\Section::make('Segurança, Conformidade & Sensores')
                        ->icon('heroicon-o-shield-check')
                        ->schema(array_filter([
                            $this->getSingleNotificationItemSchema('Análise Fora dos Limites', 'nao_conformidade', 'Alerta imediato quando um registo diário viola os parâmetros legais.', defaultMail: true),
                            $this->getSingleNotificationItemSchema('Resumo de Conformidade', 'resumo_conformidade', 'Resumo periódico com a lista de piscinas não conformes.', defaultMail: true),
                            $this->podeGerir() ? Forms\Components\CheckboxList::make('digest_conformidade_horas')
                                ->label('Horários de Envio do Resumo de Conformidade')
                                ->options([
                                    '07:00' => '07:00',
                                    '08:00' => '08:00',
                                    '09:00' => '09:00',
                                    '10:00' => '10:00',
                                    '11:00' => '11:00',
                                    '12:00' => '12:00',
                                    '13:00' => '13:00',
                                    '14:00' => '14:00',
                                    '15:00' => '15:00',
                                    '16:00' => '16:00',
                                    '17:00' => '17:00',
                                    '18:00' => '18:00',
                                    '19:00' => '19:00',
                                    '20:00' => '20:00',
                                    '21:00' => '21:00',
                                    '22:00' => '22:00',
                                ])
                                ->columns([
                                    'default' => 3,
                                    'sm' => 4,
                                    'md' => 6,
                                ])
                                ->helperText('Selecione as horas exatas em que a aplicação envia o resumo de piscinas não conformes.')
                                ->columnSpanFull() : null,
                            $this->getSingleNotificationItemSchema('Parâmetros Fora na Sonda Hanna', 'hanna_threshold', 'Alerta em tempo real quando o controlador Hanna deteta valores anómalos.'),
                            $this->getSingleNotificationItemSchema('pH em Overtime na Sonda', 'hanna_overtime', 'Alerta quando a dosagem automática do controlador falha em corrigir o pH.'),
                        ]))
                        ->collapsible(),

                    // 4. Sistema
                    Forms\Components\Section::make('Avisos do Sistema')
                        ->icon('heroicon-o-megaphone')
                        ->schema([
                            $this->getSingleNotificationItemSchema('Anúncios e Avisos Globais', 'custom_broadcast', 'Comunicados e mensagens emitidas pela administração.'),
                        ])
                        ->collapsible(),
                ]),
        ];

        return $form
            ->schema($schema)
            ->statePath('preferencesData');
    }

    private function getSingleNotificationItemSchema(string $label, string $key, string $description, bool $defaultMail = false): Forms\Components\Group
    {
        return Forms\Components\Group::make([
            Forms\Components\Placeholder::make("label_{$key}")
                ->label($label)
                ->content($description),
            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Toggle::make("notification_preferences.{$key}.push")
                    ->label('Push (Dispositivo)')
                    ->default(true),
                Forms\Components\Toggle::make("notification_preferences.{$key}.mail")
                    ->label('E-mail')
                    ->default($defaultMail),
            ]),
        ])->columns(1);
    }

    public function savePreferences(): void
    {
        $data = $this->preferencesForm->getState();
        $user = auth()->user();
        $user->update([
            'notification_preferences' => $data['notification_preferences'] ?? [],
        ]);

        if ($this->podeGerir() && isset($data['digest_conformidade_horas'])) {
            $settings = app(\App\Services\SettingsService::class);
            $settings->set('digest_conformidade_horas', $data['digest_conformidade_horas']);
        }

        Notification::make()
            ->title('Preferências guardadas com sucesso!')
            ->success()
            ->send();
    }

    public function mount(): void
    {
        $this->destinoCargo = UserRole::ADMIN;
        $settings = app(\App\Services\SettingsService::class);
        $digestHoras = $settings->getArray('digest_conformidade_horas', ['08:00', '13:00', '18:00']);

        $this->preferencesForm->fill([
            'notification_preferences' => auth()->user()->notification_preferences ?? [],
            'digest_conformidade_horas' => $digestHoras,
        ]);
    }

    public function podeGerir(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    public function getUsuariosNotificacoes(): \Illuminate\Support\Collection
    {
        return \App\Models\User::query()
            ->with('roles')
            ->withCount('pushSubscriptions')
            ->orderBy('name')
            ->get();
    }

    public function getUsuariosLista(): array
    {
        return \App\Models\User::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function enviarManual(): void
    {
        if (! $this->podeGerir()) {
            return;
        }

        $this->validate([
            'manualTitulo' => 'required|string|max:255',
            'manualCorpo' => 'required|string',
            'destinoTipo' => 'required|in:cargo,utilizador',
            'destinoCargo' => 'required_if:destinoTipo,cargo',
            'destinoUtilizador' => 'required_if:destinoTipo,utilizador',
        ]);

        try {
            $destinatarios = collect();

            if ($this->destinoTipo === 'cargo') {
                $destinatarios = \App\Models\User::role($this->destinoCargo)->get();
            } else {
                $u = \App\Models\User::find($this->destinoUtilizador);
                if ($u) {
                    $destinatarios->push($u);
                }
            }

            if ($destinatarios->isEmpty()) {
                Notification::make()
                    ->title('Nenhum destinatário')
                    ->body('Não foram encontrados utilizadores para os critérios selecionados.')
                    ->warning()
                    ->send();
                return;
            }

            $notificacao = new \App\Notifications\CustomBroadcastNotification(
                $this->manualTitulo,
                $this->manualCorpo,
                'manual-send-' . time()
            );

            \Illuminate\Support\Facades\Notification::send($destinatarios, $notificacao);

            Notification::make()
                ->title('Notificação enviada!')
                ->body('Enviada com sucesso para ' . $destinatarios->count() . ' utilizador(es).')
                ->success()
                ->send();

            // Limpar formulário
            $this->manualTitulo = '';
            $this->manualCorpo = '';
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erro ao enviar')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public static function rotulosCargos(): array
    {
        return [
            UserRole::ADMIN => 'Admin',
            UserRole::GESTOR => 'Gestor',
            UserRole::TECNICO => 'Técnico',
            UserRole::NADADOR_SALVADOR => 'Nadador-Salvador',
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(CustomBroadcast::query())
            ->emptyStateHeading('Sem avisos agendados')
            ->emptyStateDescription('Crie um novo aviso para enviar notificações personalizadas num determinado horário.')
            ->emptyStateIcon('heroicon-o-megaphone')
            ->headerActions([
                Tables\Actions\CreateAction::make('novo_aviso')
                    ->label('Novo Aviso')
                    ->icon('heroicon-o-megaphone')
                    ->model(CustomBroadcast::class)
                    ->form($this->getAvisoFormSchema())
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();
                        return $data;
                    })
                    ->visible(fn (): bool => $this->podeGerir()),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('titulo')
                    ->label('Título')
                    ->searchable(),
                Tables\Columns\TextColumn::make('cargos')
                    ->label('Cargos')
                    ->formatStateUsing(function ($state): string {
                        $cargosArray = is_string($state) ? json_decode($state, true) : $state;
                        $cargosArray = is_array($cargosArray) ? $cargosArray : [];
                        return collect($cargosArray)
                            ->map(fn (string $cargo) => self::rotulosCargos()[$cargo] ?? $cargo)
                            ->join(', ');
                    }),
                Tables\Columns\TextColumn::make('tipo_agendamento')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state): string => $state === CustomBroadcast::TIPO_DIARIO ? 'Diário' : 'Único'),
                Tables\Columns\TextColumn::make('quando')
                    ->label('Quando')
                    ->state(fn (CustomBroadcast $record): string => $record->tipo_agendamento === CustomBroadcast::TIPO_DIARIO
                        ? 'Todos os dias às ' . \Illuminate\Support\Carbon::parse($record->hora_diaria)->format('H:i')
                        : ($record->enviar_em?->format('d/m/Y H:i') ?? '—')),
                Tables\Columns\TextColumn::make('estado')
                    ->label('Estado')
                    ->state(function (CustomBroadcast $record): string {
                        if ($record->tipo_agendamento === CustomBroadcast::TIPO_DIARIO) {
                            return $record->ativo ? 'Ativo' : 'Pausado';
                        }
                        return $record->enviado_em ? 'Enviado' : 'Agendado';
                    })
                    ->badge()
                    ->color(function (CustomBroadcast $record): string {
                        if ($record->tipo_agendamento === CustomBroadcast::TIPO_DIARIO) {
                            return $record->ativo ? 'success' : 'gray';
                        }
                        return $record->enviado_em ? 'success' : 'warning';
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form($this->getAvisoFormSchema())
                    ->visible(fn (): bool => $this->podeGerir()),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (): bool => $this->podeGerir()),
            ]);
    }

    private function getAvisoFormSchema(): array
    {
        return [
            Forms\Components\Section::make('Mensagem')
                ->schema([
                    Forms\Components\TextInput::make('titulo')
                        ->label('Título')
                        ->required()
                        ->maxLength(150),
                    Forms\Components\Textarea::make('corpo')
                        ->label('Mensagem')
                        ->required()
                        ->rows(3),
                ]),
            Forms\Components\Section::make('Destinatários')
                ->schema([
                    Forms\Components\CheckboxList::make('cargos')
                        ->label('Enviar para')
                        ->options(self::rotulosCargos())
                        ->required()
                        ->columns(2),
                ]),
            Forms\Components\Section::make('Quando')
                ->schema([
                    Forms\Components\Radio::make('tipo_agendamento')
                        ->label('Tipo de envio')
                        ->options([
                            CustomBroadcast::TIPO_UNICO => 'Envio único (data/hora exata)',
                            CustomBroadcast::TIPO_DIARIO => 'Diário (recorrente, a uma hora fixa)',
                        ])
                        ->default(CustomBroadcast::TIPO_UNICO)
                        ->live()
                        ->required(),
                    Forms\Components\DateTimePicker::make('enviar_em')
                        ->label('Enviar em')
                        ->native(false)
                        ->minDate(now())
                        ->required(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_UNICO)
                        ->visible(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_UNICO),
                    Forms\Components\TimePicker::make('hora_diaria')
                        ->label('Hora do dia')
                        ->seconds(false)
                        ->required(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_DIARIO)
                        ->visible(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_DIARIO),
                    Forms\Components\Toggle::make('ativo')
                        ->label('Ativo')
                        ->helperText('Desativa para pausar os envios diários sem apagar a notificação.')
                        ->default(true)
                        ->visible(fn (Get $get) => $get('tipo_agendamento') === CustomBroadcast::TIPO_DIARIO),
                ]),
        ];
    }
}
