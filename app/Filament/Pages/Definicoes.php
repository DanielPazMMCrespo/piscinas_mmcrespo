<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\AppSetting;
use App\Models\CustomBroadcast;
use App\Models\DailyRecord;
use App\Models\User;
use App\Notifications\CustomBroadcastNotification;
use App\Notifications\PedidoAtivacaoPushNotification;
use App\Notifications\TesteNotificacaoPush;
use App\Services\CacheService;
use App\Services\SettingsService;
use App\Support\Auditoria;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Página única de Definições: junta o que era "Definições do Sistema" e
 * "Notificações" em três separadores — Minhas Notificações (todos), Sistema
 * e Avisos (admin) — em vez de dois itens de menu separados.
 */
class Definicoes extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Definições';

    protected static ?string $title = 'Definições';

    protected static ?int $navigationSort = 10;

    protected static string $view = 'filament.pages.definicoes';

    public string $tab = 'notificacoes';

    public bool $mostrarAvancado = false;

    public ?array $data = [];

    public ?array $preferencesData = [];

    public string $destinoTipo = 'cargo';

    public string $destinoCargo = 'admin';

    public ?int $destinoUtilizador = null;

    public string $manualTitulo = '';

    public string $manualCorpo = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    public function mount(): void
    {
        $this->tab = $this->podeGerir() ? 'sistema' : 'notificacoes';
        $this->destinoCargo = UserRole::ADMIN;

        if ($this->podeGerir()) {
            $settings = AppSetting::all()->pluck('value', 'key')->toArray();
            $this->form->fill($settings);
        }

        $this->preferencesForm->fill([
            'notification_preferences' => auth()->user()->notification_preferences ?? [],
        ]);
    }

    public function podeGerir(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    protected function getForms(): array
    {
        return [
            'form',
            'preferencesForm',
        ];
    }

    private function opcoesHorario(): array
    {
        $horas = [];

        foreach (range(6, 23) as $h) {
            $label = sprintf('%02d:00', $h);
            $horas[$label] = $label;
        }

        return $horas;
    }

    // ==================================================================
    // Separador "Sistema" (admin)
    // ==================================================================

    public function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Limites Regulamentares (CN 14/DA)')
                    ->description('Limites legais para a qualidade da água das piscinas.')
                    ->icon('heroicon-o-scale')
                    ->schema([
                        Forms\Components\Placeholder::make('aviso_legal')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<div class="p-4 rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 text-sm flex gap-3">'.
                                '<span class="font-semibold text-base">⚠️ Atenção:</span>'.
                                '<span>Alterar estes limites afeta a conformidade legal exibida no painel e relatórios. Certifique-se de que os valores cumprem a regulamentação em vigor.</span>'.
                                '</div>'
                            ))
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('ph_min')
                            ->label('pH Mínimo')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 6.9'),
                        Forms\Components\TextInput::make('ph_max')
                            ->label('pH Máximo')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 8.0'),
                        Forms\Components\TextInput::make('cloro_livre_min')
                            ->label('Cloro Livre Mínimo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 0.5'),
                        Forms\Components\TextInput::make('cloro_livre_max')
                            ->label('Cloro Livre Máximo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 2.0'),
                        Forms\Components\TextInput::make('cloro_combinado_max')
                            ->label('Cloro Combinado Máximo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 0.6'),
                        Forms\Components\TextInput::make('transparencia_max')
                            ->label('Turbidez Máxima (FNU)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 5.0'),
                        Forms\Components\TextInput::make('tolerancia_amarelo')
                            ->label('Tolerância (Aviso Amarelo)')
                            ->numeric()
                            ->step(0.01)
                            ->helperText('Diferença para o limite (Padrão: 0.2)'),
                    ])->columns(2),

                Forms\Components\Section::make('Horários de Resumos')
                    ->description('A que horas a aplicação envia os resumos automáticos à equipa.')
                    ->icon('heroicon-o-clock')
                    ->schema([
                        Forms\Components\Select::make('resumo_turno_horas')
                            ->label('Resumo de Turno (Máx. 4)')
                            ->options($this->opcoesHorario())
                            ->multiple()
                            ->maxItems(4)
                            ->searchable()
                            ->helperText('Resumo operacional do turno. Padrão: 14:00 e 20:00.'),
                        Forms\Components\Select::make('digest_conformidade_horas')
                            ->label('Resumo de Conformidade (Máx. 4)')
                            ->options($this->opcoesHorario())
                            ->multiple()
                            ->maxItems(4)
                            ->searchable()
                            ->helperText('Resumo de piscinas não conformes. Padrão: 08:00, 13:00 e 18:00.'),
                        Forms\Components\TextInput::make('fator_compensacao_dosagem')
                            ->label('Fator de Compensação (Dosagem)')
                            ->numeric()
                            ->step(0.05)
                            ->helperText('Multiplica a dose calculada para compensar filtros, utilização, etc. (Padrão: 1.25 = +25%)')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Definições Avançadas')
                    ->description('Prazos e regras de negócio que raramente precisam de ser alterados depois da configuração inicial.')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->visible(fn () => $this->mostrarAvancado)
                    ->schema([
                        Forms\Components\TextInput::make('sensor_fresco_minutos')
                            ->label('Validade da Leitura do Dashboard (Minutos)')
                            ->numeric()
                            ->helperText('Até quanto tempo a leitura da sonda é considerada "válida" no painel. (Padrão: 240)'),
                        Forms\Components\TextInput::make('sensor_timeout_minutos')
                            ->label('Timeout da Sonda (Minutos)')
                            ->numeric()
                            ->helperText('Tempo sem resposta da sonda até disparar o alerta de falha de comunicação. (Padrão: 30)'),
                        Forms\Components\TextInput::make('convite_validade_horas')
                            ->label('Validade do Convite (Horas)')
                            ->numeric()
                            ->helperText('Quanto tempo o link do convite demora a expirar. (Padrão: 48)'),
                        Forms\Components\TextInput::make('torneira_aberta_horas_aviso')
                            ->label('Aviso de Torneira Aberta (Horas)')
                            ->numeric()
                            ->helperText('Horas com a torneira aberta até notificar admin/técnico. (Padrão: 4)'),
                        Forms\Components\TextInput::make('sonda_online_minutos')
                            ->label('Sonda Considerada "Online" Até (Minutos)')
                            ->numeric()
                            ->helperText('Minutos desde a última leitura da sonda Hanna para o dashboard a mostrar como fonte ativa. (Padrão: 60)'),
                        Forms\Components\TextInput::make('registo_manual_validade_horas')
                            ->label('Registo Manual Válido Até (Horas)')
                            ->numeric()
                            ->helperText('Horas desde o último registo manual para ainda ser usado como fonte no dashboard, se a sonda não estiver online. (Padrão: 8)'),
                        Forms\Components\TextInput::make('sem_registo_hora_critica')
                            ->label('Hora do Dia — "Sem Registo" Torna-se Crítico')
                            ->numeric()
                            ->helperText('A partir desta hora do dia, uma piscina sem registo diário passa de aviso amarelo a alerta vermelho. (Padrão: 12)'),
                        Forms\Components\TextInput::make('tendencia_registos_minimos')
                            ->label('Registos para Deteção de Tendência')
                            ->numeric()
                            ->helperText('Número mínimo de registos consecutivos para detetar tendências degradantes. (Padrão: 3)'),
                        Forms\Components\TextInput::make('tendencia_orp_delta_mv')
                            ->label('Descida de ORP que Confirma Tendência (mV)')
                            ->numeric()
                            ->helperText('Quanto o ORP tem de descer na mesma janela para confirmar uma tendência degradante do cloro livre. Se o ORP subiu ou se manteve, o alerta não é enviado — a sonda compensou sozinha. (Padrão: 10)'),
                        Forms\Components\TextInput::make('auto_incidente_violacoes_minimas')
                            ->label('Violações para Auto-Incidente')
                            ->numeric()
                            ->helperText('Quantas violações do mesmo parâmetro no mesmo dia/piscina disparam um incidente automático. (Padrão: 3)'),
                        Forms\Components\TextInput::make('escalacao_incidente_horas')
                            ->label('Escalar Incidente Sem Resposta (Horas)')
                            ->numeric()
                            ->helperText('Horas sem mensagens novas num incidente aberto até notificar admin/gestor. (Padrão: 24)'),
                        Forms\Components\TextInput::make('incidentes_kanban_dias')
                            ->label('Incidentes Mostrados no Kanban (Dias)')
                            ->numeric()
                            ->helperText('Janela de dias de incidentes ainda não resolvidos mostrados no quadro operacional. (Padrão: 30)'),
                    ])->columns(2),

                Forms\Components\Section::make('Templates de Email')
                    ->description('Personalize o assunto e corpo dos emails automáticos enviados pela aplicação.')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn () => $this->mostrarAvancado)
                    ->schema([
                        Forms\Components\TextInput::make('email_convite_assunto')
                            ->label('Assunto do Email (Convite)')
                            ->placeholder('Convite — Piscinas MMCrespo')
                            ->helperText('Predefinição: Convite — Piscinas MMCrespo')
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('email_convite_mensagem')
                            ->label('Mensagem do Corpo (Convite)')
                            ->rows(3)
                            ->placeholder('Foi convidado(a) para aceder à plataforma de gestão operacional das Piscinas de Leiria, Maceira e Caranguejeira desenvolvido pela MMCrespo. Clique no botão abaixo para completar o seu registo e ativar a conta:')
                            ->helperText('Predefinição: Foi convidado(a) para aceder à plataforma de gestão operacional das Piscinas de Leiria, Maceira e Caranguejeira desenvolvido pela MMCrespo. Clique no botão abaixo para completar o seu registo e ativar a conta:')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless($this->podeGerir(), 403);

        $data = $this->form->getState();

        // Estado anterior capturado antes do loop: os limites regulamentares
        // CN 14/DA mudam aqui e uma alteração destas tem de ficar auditável.
        // AppSetting não entra no trilho por trait — a PK é uma string e a
        // coluna `subject_id` do activity_log é inteira.
        $antes = AppSetting::all()->pluck('value', 'key')->all();

        if (! $this->validarLimitesCruzados($data, $antes)) {
            return;
        }

        $alteracoes = [];

        foreach ($data as $key => $value) {
            $anterior = $antes[$key] ?? null;
            $novo = ($value === null || $value === '' || $value === []) ? null : $value;

            // Comparação frouxa de propósito: o form devolve '6.9' onde a BD
            // tem 6.9 — com === todo o Guardar apareceria como alteração.
            if ($anterior != $novo) {
                $alteracoes[$key] = ['de' => $anterior, 'para' => $novo];
            }

            // Campo deixado em branco significa "usar o valor padrão", não "gravar
            // vazio": apagar a linha faz o SettingsService cair no default do
            // código. Gravar '' punha os limites regulamentares a zero.
            if ($novo === null) {
                AppSetting::where('key', $key)->delete();

                continue;
            }

            $setting = AppSetting::find($key);
            if ($setting) {
                $setting->update(['value' => $value]);
            } else {
                AppSetting::create([
                    'key' => $key,
                    'value' => $value,
                    'group' => 'geral',
                    'label' => ucwords(str_replace('_', ' ', $key)),
                    'type' => 'string',
                ]);
            }
        }

        app(SettingsService::class)->flush();

        app(CacheService::class)->invalidatePoolData();
        app(CacheService::class)->invalidateAllAlerts();

        if ($alteracoes !== []) {
            Auditoria::registar(
                Auditoria::CANAL_DEFINICOES,
                'Alterou as definições do sistema ('.count($alteracoes).' '.(count($alteracoes) === 1 ? 'campo' : 'campos').').',
                ['old' => array_map(fn (array $d) => $d['de'], $alteracoes),
                    'attributes' => array_map(fn (array $d) => $d['para'], $alteracoes)],
            );
        }

        Notification::make()
            ->title('Definições atualizadas')
            ->body('As definições do sistema foram guardadas com sucesso e a cache foi limpa.')
            ->success()
            ->send();
    }

    /**
     * Um mínimo >= máximo (nalgum destes pares) põe `DailyRecord::avaliarConformidade()`
     * a testar isBelowMin antes de isAboveMax e a devolver sempre "abaixo do mínimo"
     * para todos os valores, em todas as piscinas — falso alarme permanente no
     * dashboard, no semáforo do formulário, nos incidentes automáticos e no livro
     * sanitário. Compara o valor "efetivo" (o que vem agora do form, senão o que já
     * estava gravado, senão o padrão) porque só olhar para o form deixava passar
     * "só mudei o máximo e ficou abaixo do mínimo que continua gravado".
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $antes
     */
    private function validarLimitesCruzados(array $data, array $antes): bool
    {
        $efetivo = function (string $chave, float $default) use ($data, $antes): float {
            foreach ([$data[$chave] ?? null, $antes[$chave] ?? null] as $candidato) {
                if ($candidato !== null && $candidato !== '' && $candidato !== []) {
                    return (float) $candidato;
                }
            }

            return $default;
        };

        $pares = [
            ['min' => 'ph_min', 'max' => 'ph_max', 'label' => 'pH', 'default_min' => DailyRecord::PH_MIN, 'default_max' => DailyRecord::PH_MAX],
            ['min' => 'cloro_livre_min', 'max' => 'cloro_livre_max', 'label' => 'Cloro Livre', 'default_min' => DailyRecord::CLORO_LIVRE_MIN, 'default_max' => DailyRecord::CLORO_LIVRE_MAX],
        ];

        foreach ($pares as $par) {
            $min = $efetivo($par['min'], $par['default_min']);
            $max = $efetivo($par['max'], $par['default_max']);

            if ($min >= $max) {
                Notification::make()
                    ->title('Erro de validação')
                    ->body("{$par['label']} Mínimo ({$min}) tem de ser inferior ao {$par['label']} Máximo ({$max}).")
                    ->danger()
                    ->send();

                return false;
            }
        }

        return true;
    }

    // ==================================================================
    // Separador "Minhas Notificações" (todos)
    // ==================================================================

    public function preferencesForm(Forms\Form $form): Forms\Form
    {
        $schema = [
            Forms\Components\Section::make('Preferências de Notificação')
                ->description('Personalize exatamente quais notificações deseja receber por Push (no dispositivo/browser) e por E-mail.')
                ->schema([
                    Forms\Components\Section::make('Incidentes e Ocorrências')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->schema([
                            $this->getSingleNotificationItemSchema('Novo Incidente', 'incident_created', 'Receber aviso quando um novo incidente é reportado.'),
                            $this->getSingleNotificationItemSchema('Mensagens em Incidentes', 'incident_message', 'Notificações de novas mensagens e respostas no chat de um incidente.'),
                            $this->getSingleNotificationItemSchema('Incidente Sem Resposta 24h', 'escalacao_incidente', 'Aviso quando um incidente aberto fica 24h sem mensagens novas.'),
                        ])
                        ->collapsible()
                        ->visible(fn () => ! auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)),

                    Forms\Components\Section::make('Operação e Casa das Máquinas')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->schema([
                            $this->getSingleNotificationItemSchema('Fim de Temporizador', 'timer_finished', 'Alerta quando o temporizador da retrolavagem/enxaguamento chega ao fim.'),
                            $this->getSingleNotificationItemSchema('Torneira Aberta', 'torneira_aberta', 'Alerta quando uma torneira de reposição se mantém aberta além do limite.'),
                            $this->getSingleNotificationItemSchema('Nível Baixo nos Bidões', 'dosing_low', 'Aviso quando o nível estimado de produto químico no bidão está baixo.'),
                            $this->getSingleNotificationItemSchema('Resumo de Fim de Turno', 'resumo_turno', 'Resumo operacional do turno, nos horários configurados.'),
                            $this->getSingleNotificationItemSchema('Piscina Encerrada ou Reaberta', 'piscina_encerrada', 'Aviso quando uma piscina é encerrada (fim de época, obra, avaria) ou volta à operação.'),
                            $this->getSingleNotificationItemSchema('Pedido de Acesso (piscina encerrada)', 'pedido_acesso', 'Aviso quando um nadador-salvador bloqueado pede acesso à app.'),
                        ])
                        ->collapsible()
                        ->visible(fn () => ! auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)),

                    Forms\Components\Section::make('Segurança, Conformidade & Sensores')
                        ->icon('heroicon-o-shield-check')
                        ->schema([
                            $this->getSingleNotificationItemSchema('Análise Fora dos Limites', 'nao_conformidade', 'Alerta imediato quando um registo diário viola os parâmetros legais.', defaultMail: true),
                            $this->getSingleNotificationItemSchema('Resumo de Conformidade', 'resumo_conformidade', 'Resumo periódico com a lista de piscinas não conformes.', defaultMail: true),
                            $this->getSingleNotificationItemSchema('Parâmetros Fora na Sonda Hanna', 'hanna_threshold', 'Alerta em tempo real quando o controlador Hanna deteta valores anómalos.'),
                            $this->getSingleNotificationItemSchema('pH em Overtime na Sonda', 'hanna_overtime', 'Alerta quando a dosagem automática do controlador falha em corrigir o pH.'),
                            $this->getSingleNotificationItemSchema('Sondas sem Sincronização', 'hanna_sync_falhou', 'Alerta quando a Hanna Cloud recusa o login e as leituras das sondas deixam de entrar.', defaultMail: true),
                            $this->getSingleNotificationItemSchema('Tendência Degradante', 'tendencia_alerta', 'Alerta quando pH ou cloro mostram tendência a sair dos limites nos próximos dias.'),
                            $this->getSingleNotificationItemSchema('Comparação Semanal', 'comparacao_semanal', 'Resumo semanal de conformidade comparado com a semana anterior.'),
                        ])
                        ->collapsible()
                        ->visible(fn () => ! auth()->user()?->hasRole(UserRole::NADADOR_SALVADOR)),

                    Forms\Components\Section::make('Avisos do Sistema')
                        ->icon('heroicon-o-megaphone')
                        ->schema([
                            $this->getSingleNotificationItemSchema('Anúncios e Avisos Globais', 'custom_broadcast', 'Comunicados e mensagens emitidas pela administração.'),
                            $this->getSingleNotificationItemSchema('Relatório Mensal Disponível', 'relatorio_mensal', 'Aviso quando o livro sanitário do mês anterior é gerado automaticamente.'),
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

        $currentPrefs = $user->notification_preferences ?? [];
        $newPrefs = array_replace_recursive($currentPrefs, $data['notification_preferences'] ?? []);

        $user->update([
            'notification_preferences' => $newPrefs,
        ]);

        Notification::make()
            ->title('Preferências guardadas com sucesso!')
            ->success()
            ->send();
    }

    public function testarNotificacao(): void
    {
        $user = auth()->user();

        if (! $user->hasPushActive()) {
            Notification::make()
                ->title('Nenhum dispositivo ativo')
                ->body('Ative as notificações neste dispositivo antes de testar.')
                ->warning()
                ->send();

            return;
        }

        $user->notify(new TesteNotificacaoPush);

        Notification::make()
            ->title('Teste enviado')
            ->body('Devia receber uma notificação neste dispositivo nos próximos segundos.')
            ->success()
            ->send();
    }

    public function solicitarAtivacao(): void
    {
        $user = auth()->user();

        if ($user->hasPushActive()) {
            Notification::make()
                ->title('Notificações já ativas')
                ->body('Já tem notificações ativadas neste dispositivo.')
                ->info()
                ->send();

            return;
        }

        $user->requestPushNotifications();

        Notification::make()
            ->title('Pedido enviado!')
            ->body('O seu pedido de ativação de notificações foi registado. O administrador será notificado.')
            ->success()
            ->send();
    }

    // ==================================================================
    // Separador "Avisos" (admin)
    // ==================================================================

    public static function rotulosCargos(): array
    {
        return [
            UserRole::ADMIN => 'Admin',
            UserRole::GESTOR => 'Gestor',
            UserRole::TECNICO => 'Técnico',
            UserRole::NADADOR_SALVADOR => 'Nadador-Salvador',
        ];
    }

    public function getUsuariosNotificacoes(): Collection
    {
        if (! $this->podeGerir()) {
            return collect();
        }

        return User::query()
            ->with('roles')
            ->withCount('pushSubscriptions')
            ->orderBy('name')
            ->get()
            ->map(function (User $user) {
                $user->push_status = match (true) {
                    $user->push_subscriptions_count > 0 => 'ativo',
                    $user->push_notifications_requested_at !== null => 'solicitado',
                    default => 'inativo',
                };

                return $user;
            });
    }

    public function getUsuariosLista(): array
    {
        if (! $this->podeGerir()) {
            return [];
        }

        return User::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function pedirAtivacao(int $userId): void
    {
        if (! $this->podeGerir()) {
            return;
        }

        $user = User::find($userId);

        if ($user && ! $user->hasPushActive()) {
            $user->notify(new PedidoAtivacaoPushNotification);

            Notification::make()
                ->title('Pedido enviado')
                ->body("{$user->name} vai ver um aviso para ativar as notificações na próxima vez que abrir a aplicação.")
                ->success()
                ->send();
        }
    }

    public function limparSubscricoesUtilizador(int $userId): void
    {
        if (! $this->podeGerir()) {
            return;
        }

        $user = User::find($userId);

        if ($user) {
            $user->pushSubscriptions()->delete();

            Notification::make()
                ->title('Subscrições limpas')
                ->body("As subscrições de push de {$user->name} foram removidas. Peça-lhe para voltar a clicar em \"Ativar notificações\".")
                ->success()
                ->send();
        }
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
                $destinatarios = User::role($this->destinoCargo)->get();
            } else {
                $u = User::find($this->destinoUtilizador);
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

            $notificacao = new CustomBroadcastNotification(
                $this->manualTitulo,
                $this->manualCorpo,
                'manual-send-'.time()
            );

            \Illuminate\Support\Facades\Notification::send($destinatarios, $notificacao);

            Notification::make()
                ->title('Notificação enviada!')
                ->body('Enviada com sucesso para '.$destinatarios->count().' utilizador(es).')
                ->success()
                ->send();

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
                        ? 'Todos os dias às '.Carbon::parse($record->hora_diaria)->format('H:i')
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
