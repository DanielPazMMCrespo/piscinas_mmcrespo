<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\AppSetting;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DefinicoesSistema extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Definições';

    protected static ?string $title = 'Definições do Sistema';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.definicoes-sistema';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    public function mount(): void
    {
        $settings = AppSetting::all()->pluck('value', 'key')->toArray();
        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Limites Regulamentares (CN 14/DA)')
                    ->description('Limites legais para a qualidade da água das piscinas.')
                    ->icon('heroicon-o-scale')
                    ->collapsible()
                    ->schema([
                        Placeholder::make('aviso_legal')
                            ->hiddenLabel()
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div class="p-4 rounded-lg bg-amber-500/10 text-amber-700 dark:text-amber-400 border border-amber-500/20 text-sm flex gap-3">' .
                                '<span class="font-semibold text-base">⚠️ Atenção:</span>' .
                                '<span>Alterar estes limites afeta a conformidade legal exibida no painel e relatórios. Certifique-se de que os valores cumprem a regulamentação em vigor.</span>' .
                                '</div>'
                            ))
                            ->columnSpanFull(),
                        TextInput::make('ph_min')
                            ->label('pH Mínimo')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 6.9'),
                        TextInput::make('ph_max')
                            ->label('pH Máximo')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 8.0'),
                        TextInput::make('cloro_livre_min')
                            ->label('Cloro Livre Mínimo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 0.5'),
                        TextInput::make('cloro_livre_max')
                            ->label('Cloro Livre Máximo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 2.0'),
                        TextInput::make('cloro_combinado_max')
                            ->label('Cloro Combinado Máximo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 0.6'),
                        TextInput::make('transparencia_max')
                            ->label('Turbidez Máxima (FNU)')
                            ->numeric()
                            ->step(0.1)
                            ->helperText('Padrão original: 5.0'),
                        TextInput::make('tolerancia_amarelo')
                            ->label('Tolerância (Aviso Amarelo)')
                            ->numeric()
                            ->step(0.01)
                            ->helperText('Diferença para o limite (Padrão: 0.2)'),
                    ])->columns(2),

                Section::make('Tempos e Prazos')
                    ->description('Configuração de tempos de validade e alertas de falhas.')
                    ->icon('heroicon-o-clock')
                    ->collapsible()
                    ->schema([
                        TextInput::make('sensor_fresco_minutos')
                            ->label('Validade da Leitura do Dashboard (Minutos)')
                            ->numeric()
                            ->helperText('Até quanto tempo a leitura da sonda é considerada "válida" no painel. (Padrão: 240)'),
                        TextInput::make('sensor_timeout_minutos')
                            ->label('Timeout da Sonda (Minutos)')
                            ->numeric()
                            ->helperText('Tempo sem resposta da sonda até disparar o alerta de falha de comunicação. (Padrão: 30)'),
                        TextInput::make('convite_validade_horas')
                            ->label('Validade do Convite (Horas)')
                            ->numeric()
                            ->helperText('Quanto tempo o link do convite demora a expirar. (Padrão: 48)'),
                        TextInput::make('torneira_aberta_horas_aviso')
                            ->label('Aviso de Torneira Aberta (Horas)')
                            ->numeric()
                            ->helperText('Horas com a torneira aberta até notificar admin/técnico. (Padrão: 4)'),
                        \Filament\Forms\Components\Select::make('digest_conformidade_horas')
                            ->label('Horários do Resumo de Conformidade (Máx. 4)')
                            ->options([
                                '06:00' => '06:00',
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
                                '23:00' => '23:00',
                            ])
                            ->multiple()
                            ->maxItems(4)
                            ->searchable()
                            ->helperText('Escolha na combobox até 4 horários do dia em que a aplicação envia o resumo de conformidade.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Automação Operacional')
                    ->description('Configuração da calculadora de dosagem, resumos automáticos e regras de negócio.')
                    ->icon('heroicon-o-cpu-chip')
                    ->collapsible()
                    ->schema([
                        TextInput::make('fator_compensacao_dosagem')
                            ->label('Fator de Compensação (Dosagem)')
                            ->numeric()
                            ->step(0.05)
                            ->helperText('Multiplica a dose calculada para compensar filtros, utilização, etc. (Padrão: 1.25 = +25%)'),
                        \Filament\Forms\Components\Select::make('resumo_turno_horas')
                            ->label('Horários do Resumo de Turno')
                            ->options([
                                '06:00' => '06:00', '07:00' => '07:00', '08:00' => '08:00',
                                '09:00' => '09:00', '10:00' => '10:00', '11:00' => '11:00',
                                '12:00' => '12:00', '13:00' => '13:00', '14:00' => '14:00',
                                '15:00' => '15:00', '16:00' => '16:00', '17:00' => '17:00',
                                '18:00' => '18:00', '19:00' => '19:00', '20:00' => '20:00',
                                '21:00' => '21:00', '22:00' => '22:00',
                            ])
                            ->multiple()
                            ->maxItems(4)
                            ->searchable()
                            ->helperText('Horários em que a app envia o resumo operacional à equipa. (Padrão: 14:00 e 20:00)')
                            ->columnSpanFull(),
                        TextInput::make('tendencia_registos_minimos')
                            ->label('Registos para Deteção de Tendência')
                            ->numeric()
                            ->helperText('Número mínimo de registos consecutivos para detetar tendências degradantes. (Padrão: 3)'),
                        TextInput::make('auto_incidente_violacoes_minimas')
                            ->label('Violações para Auto-Incidente')
                            ->numeric()
                            ->helperText('Quantas violações do mesmo parâmetro no mesmo dia/piscina disparam um incidente automático. (Padrão: 3)'),
                    ])->columns(2),



                Section::make('Templates de Email')
                    ->description('Personalize o assunto e corpo dos emails automáticos enviados pela aplicação.')
                    ->icon('heroicon-o-envelope')
                    ->collapsible()
                    ->schema([
                        TextInput::make('email_convite_assunto')
                            ->label('Assunto do Email (Convite)')
                            ->placeholder('Convite — Piscinas MMCrespo')
                            ->helperText('Predefinição: Convite — Piscinas MMCrespo')
                            ->columnSpanFull(),
                        \Filament\Forms\Components\Textarea::make('email_convite_mensagem')
                            ->label('Mensagem do Corpo (Convite)')
                            ->rows(3)
                            ->placeholder('Foi convidado(a) para aceder à plataforma de gestão operacional das Piscinas de Leiria, Maceira e Caranguejeira desenvolvido pela MMCrespo. Clique no botão abaixo para completar o seu registo e ativar a conta:')
                            ->helperText('Predefinição: Foi convidado(a) para aceder à plataforma de gestão operacional das Piscinas de Leiria, Maceira e Caranguejeira desenvolvido pela MMCrespo. Clique no botão abaixo para completar o seu registo e ativar a conta:')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Guardar Definições')
                ->submit('save')
                ->color('primary'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            $val = $value !== null ? $value : '';
            $setting = AppSetting::find($key);
            if ($setting) {
                $setting->update(['value' => $val]);
            } else {
                AppSetting::create([
                    'key' => $key,
                    'value' => $val,
                    'group' => 'geral',
                    'label' => ucwords(str_replace('_', ' ', $key)),
                    'type' => 'string'
                ]);
            }
        }

        app(SettingsService::class)->flush();
        
        // Limpar caches do dashboard para as novas configurações entrarem em vigor imediatamente
        app(\App\Services\CacheService::class)->invalidatePoolData();
        app(\App\Services\CacheService::class)->invalidateAllAlerts();

        Notification::make()
            ->title('Definições atualizadas')
            ->body('As definições do sistema foram guardadas com sucesso e a cache foi limpa.')
            ->success()
            ->send();
    }
}
