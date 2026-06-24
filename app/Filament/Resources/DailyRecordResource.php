<?php declare(strict_types=1);
namespace App\Filament\Resources;


use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use App\Models\Pool;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
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
            $query->where('user_id', auth()->id());
        }

        return $query;
    }

    /**
     * Livro de registo sanitário é append-only (CN 14/DA).
     * Técnicos e NS criam e corrigem; apenas o admin pode editar/eliminar.
     */
    /**
     * Apenas admin, técnico e nadador-salvador criam registos.
     * O gestor é só-leitura (vê a lista mas não cria).
     */
    public static function canCreate(): bool
    {
        return auth()->user()?->hasAnyRole([
            UserRole::ADMIN,
            UserRole::TECNICO,
            UserRole::NADADOR_SALVADOR,
        ]) ?? false;
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
