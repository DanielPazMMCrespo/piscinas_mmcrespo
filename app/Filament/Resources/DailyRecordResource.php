<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Constants\NSPermission;
use App\Constants\UserRole;
use App\Filament\Resources\DailyRecordResource\Pages;
use App\Models\DailyRecord;
use Filament\Forms\Form;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['piscina.instalacao', 'piscina.encerramentos']);

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

    /** @return array<string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['observacoes', 'piscina.name', 'utilizador.name'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->piscina->name.' — '.$record->registado_em->format('d/m/Y H:i');
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Instalação' => $record->piscina->instalacao->name,
            'Técnico' => $record->utilizador->name,
        ];
    }

    /**
     * Não há página de vista/edição (registo append-only) — sem isto o
     * resultado seria filtrado por getGlobalSearchResultUrl() default (null).
     */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('index');
    }

    /**
     * Acrescenta pesquisa por data de registo (formatos: "2", "02", "dia 2", "2-08", etc).
     * Filtra em memória para compatibilidade com SQLite/PostgreSQL.
     */
    public static function getGlobalSearchResults(string $query): Collection
    {
        $results = parent::getGlobalSearchResults($query);

        if ($query === '' || $results->count() >= static::getGlobalSearchResultsLimit()) {
            return $results;
        }

        $termo = trim(strtolower(Str::ascii($query)));

        // Pesquisa por dia do mês em memória (compatível com SQLite/PostgreSQL)
        $space = static::getGlobalSearchResultsLimit() - $results->count();

        // Normalizar termo para inteiro se for numérico (aceita "02" ou "2")
        $termoInt = is_numeric($termo) ? (int) $termo : null;

        $modelClass = static::getModel();

        $recordsData = $modelClass::query()
            ->with(['piscina.instalacao', 'utilizador'])
            ->latest('registado_em')
            ->limit(500)
            ->get()
            ->filter(function (DailyRecord $record) use ($termo, $termoInt): bool {
                $diaInt = (int) $record->registado_em->format('j');

                // Match: "2", "02" (ambos normalizam para int), "dia 2", "2-08"
                return ($termoInt !== null && $termoInt === $diaInt)
                    || $termo === 'dia '.((string) $diaInt)
                    || str_starts_with($record->registado_em->format('j-m'), $termo);
            })
            ->take($space)
            ->map(fn (DailyRecord $record): GlobalSearchResult => new GlobalSearchResult(
                title: $record->piscina->name.' — '.$record->registado_em->format('d/m/Y H:i'),
                url: static::getUrl('index'),
                details: [
                    'Instalação' => $record->piscina->instalacao->name,
                    'Técnico' => $record->utilizador->name,
                ],
            ))
            ->values();

        return $results->merge($recordsData);
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
