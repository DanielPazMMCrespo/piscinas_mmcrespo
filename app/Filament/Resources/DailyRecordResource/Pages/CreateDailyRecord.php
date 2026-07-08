<?php declare(strict_types=1);
namespace App\Filament\Resources\DailyRecordResource\Pages;


use App\Filament\Resources\DailyRecordResource;
use App\Filament\Resources\DailyRecordResource\DailyRecordFormBuilder;
use App\Models\DailyRecord;
use App\Models\Pool;
use Filament\Actions\Action;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateDailyRecord extends CreateRecord
{
    protected static string $resource = DailyRecordResource::class;

    /** Guarda flag para evitar duplo-submit (previne re-entrada em create()). */
    

    /** IDs das piscinas ainda por gravar nesta visita, pela ordem da fila (exclui a atual). */
    

    /** Total de piscinas desta visita (0 = fluxo normal de piscina única). Fixado no 1º "Guardar e seguir". */
    

    
    private function conteudoModalConfirmacao()
    {
         = ->data;
         = [];
        
         = array_filter(array_unique(array_merge([['pool_id'] ?? null], ['outras_piscinas_visita'] ?? [])));
        foreach ( as ) {
             = Pool::find();
             = ['piscina_' . ] ?? [];
            
            foreach (['ph', 'cloro_livre', 'temperatura', 'transparencia'] as ) {
                if (isset([]) && [] !== '') {
                     = DailyRecord::avaliarConformidade(, [], );
                    if (['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                        [] = ( ? ->name . ': ' : '') . ['mensagem'];
                    }
                }
            }
            if (isset(['cloro_livre'], ['cloro_total']) && ['cloro_livre'] !== '' && ['cloro_total'] !== '') {
                 = (float)['cloro_total'] - (float)['cloro_livre'];
                 = DailyRecord::avaliarConformidade('cloro_combinado', , );
                if (['estado'] === \App\Enums\EstadoConformidade::VERMELHO) {
                    [] = ( ? ->name . ': ' : '') . ['mensagem'];
                }
            }
        }

        return view('filament.daily-record-modal-summary', ['problemas' => ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('Criar')
                ->action(fn () => ->create())
                ->requiresConfirmation()
                ->modalHeading('Confirmar registo')
                ->modalContent(fn () => ->conteudoModalConfirmacao())
                ->modalSubmitActionLabel('Confirmar e guardar')
                ->keyBindings(['mod+s']),
            ->getCancelFormAction(),
        ];
    }

    public function getCachedFormActions(): array
    {
        return ->getFormActions();
    }

    protected function handleRecordCreation(array ): \Illuminate\Database\Eloquent\Model
    {
         = array_filter(array_unique(array_merge([['pool_id'] ?? null], ['outras_piscinas_visita'] ?? [])));
         = null;
        
         = [
            'user_id' => ['user_id'],
            'registado_em' => ['registado_em'],
        ];

        foreach ( as ) {
             = ['piscina_' . ] ?? [];
             = array_merge(, );
            ['pool_id'] = ;
            
             = ['adicoes'] ?? [];
            unset(['adicoes']);

             = DailyRecord::create();
            
            foreach ( as ) {
                ->adicoes()->create();
            }
            
            if (!) {
                 = ;
            }
            
            \App\Jobs\ProcessDailyRecordAfterCreate::dispatch(->id, (int) auth()->id());
        }

        app(\App\Services\CacheService::class)->invalidateAlerts(auth()->id());

        return  ?? new DailyRecord();
    }

    protected function afterCreate(): void
    {
        // Handled in handleRecordCreation
    }

    protected function getRedirectUrl(): string
    {
        return ->getResource()::getUrl('index');
    }
}
