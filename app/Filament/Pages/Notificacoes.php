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

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    public function mount(): void
    {
        $this->destinoCargo = UserRole::ADMIN;
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
