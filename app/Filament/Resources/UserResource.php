<?php declare(strict_types=1);
namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Constants\UserRole;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'Utilizador';

    protected static ?string $pluralModelLabel = 'Utilizadores';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR]) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR]) ?? false;
    }

    public static function canEdit($record): bool
    {
        $user = auth()->user();
        if ($user?->hasRole(UserRole::ADMIN)) {
            return true;
        }
        // gestor só pode editar utilizadores NS (não pode editar admin/gestor/tecnico)
        if ($user?->hasRole(UserRole::GESTOR) && $record !== null) {
            return $record->hasRole(UserRole::NADADOR_SALVADOR)
                && ! $record->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR, UserRole::TECNICO]);
        }
        return false;
    }

    public static function canDelete($record): bool
    {
        if (! (auth()->user()?->hasRole(UserRole::ADMIN) ?? false)) {
            return false;
        }

        if ($record->daily_records()->exists() || $record->incidents()->exists()) {
            return false;
        }

        return true;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('first_name')
                        ->label('Primeiro nome')
                        ->required()
                        ->maxLength(100),
                    Forms\Components\TextInput::make('last_name')
                        ->label('Último nome')
                        ->required()
                        ->maxLength(100),
                ]),
                Forms\Components\TextInput::make('phone')
                    ->label('Telefone')
                    ->tel()
                    ->maxLength(20),
                Forms\Components\TextInput::make('email')
                    ->label('E-mail')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->label('Palavra-passe')
                    ->password()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->hiddenOn('edit')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->dehydrateStateUsing(fn (string $state) => \Illuminate\Support\Facades\Hash::make($state))
                    ->minLength(8)
                    ->maxLength(255),
                Forms\Components\TextInput::make('pin')
                    ->label('PIN')
                    ->password()
                    ->required(fn (string $context): bool => $context === 'create')
                    ->hiddenOn('edit')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->minLength(4)
                    ->maxLength(255)
                    ->helperText('Insira um PIN que se lembrará facilmente. Este PIN será utilizado em todos os logins.'),
                Forms\Components\Select::make('roles')
                    ->label('Cargo')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->required(fn () => auth()->user()?->hasRole(UserRole::ADMIN))
                    ->live()
                    ->visible(fn () => auth()->user()?->hasRole(UserRole::ADMIN))
                    ->disabled(fn ($record): bool =>
                        $record !== null && $record->id === auth()->id()
                    )
                    ->dehydrated(fn ($record): bool => $record === null || $record->id !== auth()->id()),
                Forms\Components\Select::make('piscinas')
                    ->label('Piscinas Atribuídas')
                    ->relationship('piscinas', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(function (Forms\Get $get): bool {
                        $user = auth()->user();
                        if ($user?->hasRole(UserRole::GESTOR)) {
                            return true;
                        }
                        $roleIds = (array) ($get('roles') ?? []);
                        if (empty($roleIds)) {
                            return false;
                        }
                        return \Spatie\Permission\Models\Role::whereIn('id', $roleIds)
                            ->where('name', UserRole::NADADOR_SALVADOR)
                            ->exists();
                    })
                    ->helperText('Piscinas às quais o nadador salvador tem acesso.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Nome')
                    ->searchable(['first_name', 'last_name', 'name'])
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('name', $direction)),
                Tables\Columns\TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefone')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Perfis')
                    ->badge()
                    ->searchable(),
                Tables\Columns\TextColumn::make('piscinas.name')
                    ->label('Piscinas')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Atualizado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('resetPassword')
                    ->label('Enviar Email de Redefinição')
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Enviar email de redefinição')
                    ->modalDescription('Tem a certeza que deseja enviar um e-mail com instruções para redefinir a palavra-passe para este utilizador?')
                    ->modalSubmitActionLabel('Sim, enviar e-mail')
                    ->action(function (User $record): void {
                        \Illuminate\Support\Facades\Password::broker()->sendResetLink(['email' => $record->email]);
                        Notification::make()
                            ->title('E-mail enviado')
                            ->body('As instruções para redefinir a palavra-passe foram enviadas.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('forceChangePassword')
                    ->label('Forçar Palavra-passe')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->form([
                        Forms\Components\TextInput::make('password')
                            ->label('Nova Palavra-passe')
                            ->password()
                            ->minLength(8)
                            ->requiredWithout('pin'),
                        Forms\Components\TextInput::make('pin')
                            ->label('Novo PIN')
                            ->password()
                            ->minLength(4)
                            ->requiredWithout('password'),
                        Forms\Components\Checkbox::make('consciencia')
                            ->label('Tenho consciência que vou alterar as credenciais deste utilizador')
                            ->required()
                    ])
                    ->action(function (User $record, array $data): void {
                        if (!empty($data['password'])) {
                            $record->password = \Illuminate\Support\Facades\Hash::make($data['password']);
                        }
                        if (!empty($data['pin'])) {
                            $record->pin = \Illuminate\Support\Facades\Hash::make($data['pin']);
                        }
                        $record->save();
                        Notification::make()
                            ->title('Credenciais alteradas com sucesso')
                            ->success()
                            ->send();
                    })
                    ->modalHeading('Forçar Alteração Manual')
                    ->modalDescription('Atenção: está prestes a definir manualmente a palavra-passe/PIN de um utilizador. Aguarde 5 segundos para confirmar.')
                    ->modalSubmitActionLabel('Confirmar Alteração')
                    ->modalSubmitAction(fn (\Filament\Actions\StaticAction $action) => $action->extraAttributes([
                        'x-data' => '{ seconds: 5, init() { const i = setInterval(() => { if (this.seconds > 0) { this.seconds-- } else { clearInterval(i) } }, 1000) } }',
                        'x-bind:disabled' => 'seconds > 0',
                        'style' => 'transition: all 0.3s;',
                    ])),
                Tables\Actions\DeleteAction::make()
                    ->before(function ($record, Tables\Actions\DeleteAction $action): void {
                        if ($record->id === auth()->id()) {
                            Notification::make()->danger()->title('Não pode eliminar a sua própria conta.')->send();
                            $action->halt();
                        }
                        if ($record->hasRole(UserRole::ADMIN) && User::role(UserRole::ADMIN)->count() <= 1) {
                            Notification::make()->danger()->title('Não é possível eliminar o único administrador.')->send();
                            $action->halt();
                        }
                        if ($record->daily_records()->exists() || $record->incidents()->exists()) {
                            Notification::make()->danger()->title('Não é possível eliminar este utilizador.')
                                ->body('Existem registos diários ou incidentes associados. Contacte o administrador.')
                                ->send();
                            $action->halt();
                        }
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records): void {
                            $records->reject(fn ($r) => $r->id === auth()->id())->each->delete();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'view' => Pages\ViewUser::route('/{record}'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
