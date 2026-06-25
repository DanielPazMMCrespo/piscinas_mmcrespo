<?php declare(strict_types=1);
namespace App\Filament\Resources;


use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DailyRecordResource extends Resource
{
    protected static ?string $model = DailyRecord::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $modelLabel = 'Registo Diário';

    protected static ?string $pluralModelLabel = 'Registos Diários';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

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
            return $user->piscinas()->exists();
        }
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()->hasRole(UserRole::ADMIN);
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
            'edit' => Pages\EditDailyRecord::route('/{record}/edit'),
        ];
    }
}
