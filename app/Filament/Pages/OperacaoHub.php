<?php declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\IncidentResource;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Pages\Page;

/**
 * Ponto de entrada único da secção Operação: em vez de dois itens no menu
 * (Registos Diários, Incidentes), mostra um ecrã de escolha. Reduz o ruído
 * da sidebar sem esconder nenhuma das duas funcionalidades.
 */
class OperacaoHub extends Page implements HasActions
{
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Operação';

    protected static ?string $navigationLabel = 'Registo Diário';

    protected static ?string $title = 'Registo Diário';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.operacao-hub';

    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function registoDiarioAction(): Action
    {
        return Action::make('registoDiario')
            ->modalHeading('Registo Diário')
            ->modalWidth('md')
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->modalContent(view('filament.pages.operacao-hub-modal'));
    }

    public function getDailyRecordUrl(): ?string
    {
        return DailyRecordResource::canViewAny() ? DailyRecordResource::getUrl('index') : null;
    }

    public function getIncidentUrl(): ?string
    {
        return IncidentResource::canViewAny() ? IncidentResource::getUrl('index') : null;
    }
}
