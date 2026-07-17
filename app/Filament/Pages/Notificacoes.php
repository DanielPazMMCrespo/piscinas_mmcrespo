<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Constants\UserRole;
use App\Models\CustomBroadcast;
use App\Models\TestPush;
use App\Notifications\TestPushNotification;
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

    public string $tipoSelecionado = 'incidente';

    public string $tituloTeste = '';

    public string $corpoTeste = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    public function mount(): void
    {
        $this->preencherDefaults();
    }

    public function podeGerir(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    public function podeTestar(): bool
    {
        return $this->podeGerir();
    }

    /** @return list<string> */
    public function tiposDeTeste(): array
    {
        return TestPushNotification::tiposValidos();
    }

    public function updatedTipoSelecionado(): void
    {
        $this->preencherDefaults();
    }

    private function preencherDefaults(): void
    {
        $defaults = TestPushNotification::defaults($this->tipoSelecionado);
        $this->tituloTeste = $defaults['title'];
        $this->corpoTeste = $defaults['body'];
    }

    public function testar(): void
    {
        if (! $this->podeTestar() || ! in_array($this->tipoSelecionado, TestPushNotification::tiposValidos(), true)) {
            return;
        }

        TestPush::create([
            'user_id' => auth()->id(),
            'tipo' => $this->tipoSelecionado,
            'titulo' => trim($this->tituloTeste) ?: null,
            'corpo' => trim($this->corpoTeste) ?: null,
            'fire_at' => now()->addSeconds(5),
        ]);

        Notification::make()
            ->title('Push de teste agendado')
            ->body('Chega daqui a ~5 segundos — já podes bloquear o ecrã.')
            ->success()
            ->send();
    }

    public function testarImediato(): void
    {
        if (! $this->podeTestar() || ! in_array($this->tipoSelecionado, TestPushNotification::tiposValidos(), true)) {
            return;
        }

        try {
            auth()->user()->notify(new TestPushNotification(
                $this->tipoSelecionado,
                trim($this->tituloTeste) ?: null,
                trim($this->corpoTeste) ?: null
            ));

            Notification::make()
                ->title('Push enviado imediatamente')
                ->body('O sinal foi disparado. Deverá recebê-lo de imediato se o dispositivo estiver ligado.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Erro no envio direto')
                ->body('Falha ao comunicar com os servidores de push: ' . $e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    public function getSchedulerStatus(): string
    {
        if (! function_exists('shell_exec')) {
            return 'shell_exec desativado no PHP';
        }
        
        $ps = shell_exec('ps aux 2>&1') ?? '';
        
        $running = str_contains($ps, 'schedule:') || str_contains($ps, 'sleep 60') || str_contains($ps, 'artisan schedule');
        
        if ($running) {
            return '✅ Ativo (Loop de agendamento detetado em background)';
        }
        
        // Vamos mostrar os primeiros 10 processos para ajudar a diagnosticar o comando de arranque real
        $lines = explode("\n", trim($ps));
        $processes = array_slice($lines, 0, 15);
        
        return '❌ Inativo (Agendador não detetado). Processos ativos no contentor:' . "\n\n" . implode("\n", $processes);
    }

    private static function rotulosCargos(): array
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
