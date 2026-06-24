<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\AppSetting;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
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
                            ->required()
                            ->helperText('Padrão original: 6.9'),
                        TextInput::make('ph_max')
                            ->label('pH Máximo')
                            ->numeric()
                            ->step(0.1)
                            ->required()
                            ->helperText('Padrão original: 8.0'),
                        TextInput::make('cloro_livre_min')
                            ->label('Cloro Livre Mínimo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->required()
                            ->helperText('Padrão original: 0.5'),
                        TextInput::make('cloro_livre_max')
                            ->label('Cloro Livre Máximo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->required()
                            ->helperText('Padrão original: 2.0'),
                        TextInput::make('cloro_combinado_max')
                            ->label('Cloro Combinado Máximo (mg/L)')
                            ->numeric()
                            ->step(0.1)
                            ->required()
                            ->helperText('Padrão original: 0.6'),
                        TextInput::make('transparencia_max')
                            ->label('Turbidez Máxima (FNU)')
                            ->numeric()
                            ->step(0.1)
                            ->required()
                            ->helperText('Padrão original: 5.0'),
                        TextInput::make('aviso_amarelo_margem')
                            ->label('Margem de Aviso Amarelo (%)')
                            ->numeric()
                            ->step(1)
                            ->required()
                            ->suffix('%')
                            ->helperText('Aproximação do limite (Padrão: 10%)'),
                    ])->columns(2),

                Section::make('Opções de Dropdowns')
                    ->description('Personalize as opções disponíveis nos formulários da aplicação.')
                    ->icon('heroicon-o-list-bullet')
                    ->collapsible()
                    ->schema([
                        KeyValue::make('tipos_incidente')
                            ->label('Tipos de Incidente')
                            ->keyLabel('Chave (DB)')
                            ->valueLabel('Nome (Visual)')
                            ->helperText('Opções do dropdown de incidentes')
                            ->columnSpanFull(),
                        KeyValue::make('modos_agua')
                            ->label('Modos de Entrada de Água')
                            ->keyLabel('Chave (DB)')
                            ->valueLabel('Nome (Visual)')
                            ->helperText('Opções para o estado da entrada de água')
                            ->columnSpanFull(),
                        KeyValue::make('tipos_operacao_filtro')
                            ->label('Operações do Filtro')
                            ->keyLabel('Chave (DB)')
                            ->valueLabel('Nome (Visual)')
                            ->helperText('Opções de verificação de filtros')
                            ->columnSpanFull(),
                    ]),

                Section::make('Alertas e Quadro Kanban')
                    ->description('Configuração do comportamento dos alertas e visualização no Dashboard.')
                    ->icon('heroicon-o-bell')
                    ->collapsible()
                    ->schema([
                        TextInput::make('sensor_stale_threshold')
                            ->label('Stale Threshold (minutos)')
                            ->numeric()
                            ->required()
                            ->suffix('min')
                            ->helperText('Tempo máximo sem leituras antes do sensor alertar inativo (Padrão: 15)'),
                        TextInput::make('hora_escalacao_sem_registo')
                            ->label('Hora Escalação "Sem Registo"')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(23)
                            ->suffix(':00')
                            ->helperText('Hora a partir da qual o alerta vira vermelho (Padrão: 12)'),
                        TextInput::make('lookback_incidentes')
                            ->label('Dias Lookback Incidentes')
                            ->numeric()
                            ->required()
                            ->suffix('dias')
                            ->helperText('Pesquisar incidentes não resolvidos destes dias (Padrão: 30)'),
                        TextInput::make('max_incidentes_kanban')
                            ->label('Máx. Incidentes no Kanban')
                            ->numeric()
                            ->required()
                            ->helperText('Limite de cartões na coluna incidentes (Padrão: 10)'),
                        TextInput::make('dias_pruning_alert_states')
                            ->label('Dias Pruning Alert States')
                            ->numeric()
                            ->required()
                            ->suffix('dias')
                            ->helperText('Limpar históricos de alertas antigos após estes dias (Padrão: 7)'),
                    ])->columns(2),

                Section::make('Frequência e Performance (Polling / Cache)')
                    ->description('Ajustes de tempo real e otimização de cache.')
                    ->icon('heroicon-o-clock')
                    ->collapsible()
                    ->schema([
                        TextInput::make('polling_painel_piscinas')
                            ->label('Intervalo Polling Painel (segundos)')
                            ->numeric()
                            ->required()
                            ->suffix('seg')
                            ->helperText('Atualização do painel de piscinas (0 para desativar. Padrão: 15)'),
                        TextInput::make('polling_kanban')
                            ->label('Intervalo Polling Kanban (segundos)')
                            ->numeric()
                            ->required()
                            ->suffix('seg')
                            ->helperText('Atualização do Quadro Operacional (0 para desativar. Padrão: 60)'),
                        TextInput::make('cache_ttl_painel')
                            ->label('Cache TTL Painel (minutos)')
                            ->numeric()
                            ->required()
                            ->suffix('min')
                            ->helperText('Tempo de cache dos dados de piscinas (Padrão: 10)'),
                        TextInput::make('cache_ttl_alertas')
                            ->label('Cache TTL Alertas (minutos)')
                            ->numeric()
                            ->required()
                            ->suffix('min')
                            ->helperText('Tempo de cache do cálculo de alertas (Padrão: 5)'),
                    ])->columns(2),

                Section::make('Limitações de Uploads')
                    ->description('Tamanhos máximos de ficheiros e limites de ficheiros.')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->collapsible()
                    ->schema([
                        TextInput::make('max_fotos_analise')
                            ->label('Máx. Fotos por Análise')
                            ->numeric()
                            ->required()
                            ->helperText('Número máximo de fotos num registo diário (Padrão: 5)'),
                        TextInput::make('max_filesize_analises')
                            ->label('Tamanho Máx. Foto Análises (KB)')
                            ->numeric()
                            ->required()
                            ->suffix('KB')
                            ->helperText('Tamanho limite de upload para análises (Padrão: 5120)'),
                        TextInput::make('max_filesize_filtros')
                            ->label('Tamanho Máx. Foto Filtros (KB)')
                            ->numeric()
                            ->required()
                            ->suffix('KB')
                            ->helperText('Tamanho limite de upload para fotos de filtros (Padrão: 10240)'),
                    ])->columns(3),

                Section::make('Templates de Email')
                    ->description('Personalize o assunto e corpo dos emails automáticos enviados pela aplicação.')
                    ->icon('heroicon-o-envelope')
                    ->collapsible()
                    ->schema([
                        TextInput::make('email_convite_assunto')
                            ->label('Assunto do Email (Convite)')
                            ->required()
                            ->columnSpanFull(),
                        \Filament\Forms\Components\Textarea::make('email_convite_mensagem')
                            ->label('Mensagem do Corpo (Convite)')
                            ->required()
                            ->rows(3)
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
            $setting = AppSetting::find($key);
            if ($setting) {
                $setting->update(['value' => $value]);
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
