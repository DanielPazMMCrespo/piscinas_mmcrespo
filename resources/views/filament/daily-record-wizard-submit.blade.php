{{-- Só é renderizado no último passo do Wizard (comportamento nativo do
     ->submitAction() do Filament) — substitui a barra "Criar/Cancelar" da
     page, que antes aparecia em todos os passos. --}}
<div class="flex items-center gap-3">
    <x-filament::button
        type="button"
        wire:click="submeterFormulario"
        icon="heroicon-o-check-circle"
    >
        {{ $label }}
    </x-filament::button>

    <x-filament::button
        type="button"
        tag="a"
        color="gray"
        href="{{ \App\Filament\Resources\DailyRecordResource::getUrl('index') }}"
    >
        Cancelar
    </x-filament::button>
</div>
