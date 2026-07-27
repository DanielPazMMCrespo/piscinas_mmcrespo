<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\UserRole;
use App\Filament\Resources\UserInvitationResource\Pages;
use App\Models\UserInvitation;
use App\Services\InvitationService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listagem e gestão dos convites de utilizador (pendentes, expirados, aceites).
 * Read-only exceto pelas ações "Reenviar" (regenera token + reenvia email) e
 * "Revogar" (apaga um convite ainda não aceite). Sem criar/editar aqui — os
 * convites nascem no UserResource.
 */
class UserInvitationResource extends Resource
{
    protected static ?string $model = UserInvitation::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'Convite';

    protected static ?string $pluralModelLabel = 'Convites';

    protected static ?int $navigationSort = 45;

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole([UserRole::ADMIN, UserRole::GESTOR]) ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('invitedBy'))
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('role')
                    ->label('Cargo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => UserRole::LABELS[$state] ?? $state),
                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (UserInvitation $r): string => $r->isAccepted() ? 'Aceite' : ($r->isExpired() ? 'Expirado' : 'Pendente'))
                    ->color(fn (UserInvitation $r): string => $r->isAccepted() ? 'success' : ($r->isExpired() ? 'danger' : 'warning')),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label('Expira')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('invitedBy.name')
                    ->label('Convidado por')
                    ->default('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Enviado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('pendentes')
                    ->label('Só pendentes')
                    ->query(fn (Builder $query): Builder => $query->whereNull('accepted_at')->where('expires_at', '>', now())),
            ])
            ->actions([
                Tables\Actions\Action::make('reenviar')
                    ->label('Reenviar')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (UserInvitation $record): bool => ! $record->isAccepted())
                    ->requiresConfirmation()
                    ->modalHeading('Reenviar convite')
                    ->modalDescription(fn (UserInvitation $record): string => "Vai gerar um novo link e enviar novo email para {$record->email}. O link anterior deixa de funcionar.")
                    ->action(function (UserInvitation $record): void {
                        try {
                            app(InvitationService::class)->resend($record);
                            Notification::make()
                                ->success()
                                ->title('Convite reenviado')
                                ->body("Novo email enviado para {$record->email}.")
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->danger()
                                ->title('Não foi possível reenviar')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make()
                    ->label('Revogar')
                    ->visible(fn (UserInvitation $record): bool => ! $record->isAccepted()),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUserInvitations::route('/'),
        ];
    }
}
