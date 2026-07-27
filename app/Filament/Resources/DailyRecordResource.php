<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * [AI_CONTEXT]
 *
 * IDEALIZADO:
 * Formulário principal de registo diário para técnicos e nadadores salvadores (operações de piscina).
 * Serve para inserir os parâmetros da água (pH, Cloro, etc), contadores, estado dos filtros,
 * e registar a adição de químicos que serão debitados do stock da instalação.
 *
 * IMPLEMENTADO:
 * - Validação em tempo real (semáforo verde/amarelo/vermelho) contra limites legais (CN 14/DA).
 * - Adições de químicos debitam automaticamente o stock da Instalação (via transações na BD).
 * - Arquitetura "Append-Only": Registos não devem ser editados in-place nem apagados. Se houver erro,
 *   cria-se um novo registo de correção (`e_correcao = true`) apontando para o original.
 * - UX Reativa: Campos dependem do papel do utilizador e dos limites violados.
 *
 * EM FALTA (ROADMAP):
 * - N/A
 */
class DailyRecordResource extends Resource
{
    protected static ?string $model = DailyRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Registo Diário';

    protected static ?string $navigationLabel = 'Registos Diários';

    protected static ?string $modelLabel = 'Registo Diário';

    protected static ?string $pluralModelLabel = 'Registos Diários';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['piscina.instalacao']);

        if (auth()->user()->hasRole(UserRole::NADADOR_SALVADOR)) {
            $query->whereIn('pool_id', auth()->user()->piscinas()->pluck('pools.id'));
        }

        return $query;
    }

    /**
     * Livro de registo sanitário é append-only (CN 14/DA).
     * Técnicos e NS criam e corrigem; apenas o admin pode editar/eliminar.
     */
    public static function canCreate(): bool
    {
        $user = auth()->user();
        if ($user?->hasAnyRole([UserRole::ADMIN, UserRole::TECNICO])) {
            return true;
        }
        if ($user?->hasRole(UserRole::NADADOR_SALVADOR)) {
            return $user->podeVer(NSPermission::REGISTO_DIARIO) && $user->piscinas()->exists();
        }

        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
    }

    public static function form(Form $form): Form
    {
        return DailyRecordResource\DailyRecordFormBuilder::form($form);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return DailyRecordResource\DailyRecordTableBuilder::infolist($infolist);
    }

    public static function table(Table $table): Table
    {
        return DailyRecordResource\DailyRecordTableBuilder::table($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyRecords::route('/'),
            'create' => Pages\CreateDailyRecord::route('/create'),
        ];
    }
}
