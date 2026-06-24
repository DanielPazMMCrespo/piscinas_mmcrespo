<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center justify-end gap-x-6">
            {{ $this->getFormActions()[0] }}
        </div>
    </form>
</x-filament-panels::page>
