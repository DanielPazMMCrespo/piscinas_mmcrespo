<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Actions\StaticAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Role;

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

        if (self::temDadosAssociados($record)) {
            return false;
        }

        return true;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->hasRole(UserRole::ADMIN) ?? false;
    }

    private static function temDadosAssociados(User $record): bool
    {
        return $record->daily_records()->exists() || $record->incidents()->exists();
    }

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->full_name;
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Email' => $record->email,
            'Cargo' => $record->roles->pluck('name')->map(fn (string $role): string => match ($role) {
                UserRole::ADMIN => 'Admin',
                UserRole::GESTOR => 'Gestor',
                UserRole::TECNICO => 'Técnico',
                UserRole::NADADOR_SALVADOR => 'Nadador-Salvador',
                UserRole::INATIVO => 'Inativo',
                default => $role,
            })->implode(', ') ?: '—',
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('roles');
    }

    private static function rolesIncluemNS(Forms\Get $get): bool
    {
        $roleIds = (array) ($get('roles') ?? []);
        if (empty($roleIds)) {
            return false;
        }

        return Role::whereIn('id', $roleIds)
            ->where('name', UserRole::NADADOR_SALVADOR)
            ->exists();
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
                Forms\Components\Placeholder::make('primeiro_acesso_info')
                    ->label('Palavra-passe / PIN')
                    ->content('Não definidas aqui. O utilizador recebe a password inicial "password" e é obrigado a defini-las no primeiro acesso.')
                    ->visibleOn('create'),
                Forms\Components\Select::make('roles')
                    ->label('Cargo')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => match ($record->name) {
                        UserRole::ADMIN => 'Admin',
                        UserRole::GESTOR => 'Gestor',
                        UserRole::TECNICO => 'Técnico',
                        UserRole::NADADOR_SALVADOR => 'Nadador-Salvador',
                        UserRole::INATIVO => 'Inativo',
                        default => $record->name,
                    })
                    ->multiple()
                    ->preload()
                    ->required(fn () => auth()->user()?->hasRole(UserRole::ADMIN))
                    ->live()
                    ->visible(fn () => auth()->user()?->hasRole(UserRole::ADMIN))
                    ->disabled(fn ($record): bool => $record !== null && $record->id === auth()->id()
                    )
                    ->dehydrated(fn ($record): bool => $record === null || $record->id !== auth()->id()),
                Forms\Components\Select::make('piscinas')
                    ->label('Piscinas Atribuídas')
                    ->relationship('piscinas', 'name')
                    ->multiple()
                    ->preload()
                    ->visible(fn (Forms\Get $get): bool => auth()->user()?->hasRole(UserRole::GESTOR)
                        || self::rolesIncluemNS($get))
                    ->helperText('Piscinas às quais o nadador salvador tem acesso.'),
                Forms\Components\CheckboxList::make('ns_permissions')
                    ->label('O que este utilizador consegue ver')
                    ->options(NSPermission::labels())
                    ->default(NSPermission::all())
                    ->afterStateHydrated(fn (Forms\Components\CheckboxList $component, $state) => $state === null
                        ? $component->state(NSPermission::all())
                        : null)
                    ->columns(1)
                    ->visible(fn (Forms\Get $get): bool => auth()->user()?->hasRole(UserRole::GESTOR)
                        || self::rolesIncluemNS($get))
                    ->helperText('Secções visíveis para este nadador salvador. Sem seleção, não vê nada.'),
            ]);
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            UserRole::ADMIN => 'Admin',
            UserRole::GESTOR => 'Gestor',
            UserRole::TECNICO => 'Técnico',
            UserRole::NADADOR_SALVADOR => 'Nadador-Salvador',
            UserRole::INATIVO => 'Inativo',
            default => $role,
        };
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()
                ->schema([
                    Infolists\Components\TextEntry::make('full_name')
                        ->label('Nome')
                        ->icon('heroicon-o-user')
                        ->size(Infolists\Components\TextEntry\TextEntrySize::Large),
                    Infolists\Components\TextEntry::make('roles.name')
                        ->label('Cargo')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => self::roleLabel($state)),
                    Infolists\Components\TextEntry::make('email')
                        ->label('E-mail')
                        ->icon('heroicon-o-envelope'),
                    Infolists\Components\TextEntry::make('phone')
                        ->label('Telefone')
                        ->icon('heroicon-o-phone')
                        ->placeholder('—'),
                ])
                ->columns(2),

            Infolists\Components\Section::make('Piscinas atribuídas')
                ->icon('heroicon-o-view-columns')
                ->visible(fn (User $record) => $record->piscinas->isNotEmpty())
                ->schema([
                    Infolists\Components\TextEntry::make('piscinas.name')
                        ->hiddenLabel()
                        ->badge(),
                ]),

            Infolists\Components\Section::make('O que este utilizador consegue ver')
                ->icon('heroicon-o-eye')
                ->visible(fn (User $record) => $record->hasRole(UserRole::NADADOR_SALVADOR))
                ->schema([
                    Infolists\Components\TextEntry::make('ns_permissions')
                        ->hiddenLabel()
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => NSPermission::labels()[$state] ?? $state),
                ]),

            Infolists\Components\Section::make('Registo')
                ->icon('heroicon-o-clock')
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Criado em')
                        ->dateTime('d/m/Y H:i'),
                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Atualizado em')
                        ->dateTime('d/m/Y H:i'),
                ])
                ->columns(2),
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
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        UserRole::ADMIN => 'Admin',
                        UserRole::GESTOR => 'Gestor',
                        UserRole::TECNICO => 'Técnico',
                        UserRole::NADADOR_SALVADOR => 'Nadador-Salvador',
                        UserRole::INATIVO => 'Inativo',
                        default => $state,
                    })
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
                    // canEdit() bloqueia editar admins, mas estas duas ações não
                    // passavam por lá: o gestor via-as nas linhas dos admins.
                    ->visible(fn (User $record): bool => static::canEdit($record))
                    ->requiresConfirmation()
                    ->modalHeading('Enviar email de redefinição')
                    ->modalDescription('Tem a certeza que deseja enviar um e-mail com instruções para redefinir a palavra-passe para este utilizador?')
                    ->modalSubmitActionLabel('Sim, enviar e-mail')
                    ->action(function (User $record): void {
                        abort_unless(static::canEdit($record), 403);

                        Password::broker()->sendResetLink(['email' => $record->email]);
                        Notification::make()
                            ->title('E-mail enviado')
                            ->body('As instruções para redefinir a palavra-passe foram enviadas.')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\Action::make('forceChangePassword')
                    ->label('Forçar Pass / PIN')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->visible(fn (User $record): bool => static::canEdit($record))
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
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        abort_unless(static::canEdit($record), 403);

                        if (! empty($data['password'])) {
                            $record->password = Hash::make($data['password']);
                        }
                        if (! empty($data['pin'])) {
                            $record->pin = Hash::make($data['pin']);
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
                    ->modalSubmitAction(fn (StaticAction $action) => $action->extraAttributes([
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
                        if (self::temDadosAssociados($record)) {
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
                            $adminCount = User::role(UserRole::ADMIN)->count();
                            $skipped = [];

                            $records->each(function (User $record) use (&$adminCount, &$skipped): void {
                                if ($record->id === auth()->id()) {
                                    $skipped[] = $record->full_name;

                                    return;
                                }
                                if ($record->hasRole(UserRole::ADMIN) && $adminCount <= 1) {
                                    $skipped[] = $record->full_name;

                                    return;
                                }
                                if (self::temDadosAssociados($record)) {
                                    $skipped[] = $record->full_name;

                                    return;
                                }
                                if ($record->hasRole(UserRole::ADMIN)) {
                                    $adminCount--;
                                }
                                $record->delete();
                            });

                            if (! empty($skipped)) {
                                Notification::make()
                                    ->danger()
                                    ->title('Alguns utilizadores não foram eliminados')
                                    ->body('Têm registos diários/incidentes associados, são o único admin, ou é a sua própria conta: '.implode(', ', $skipped))
                                    ->send();
                            }
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
