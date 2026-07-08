<x-filament-panels::page>
    <div class="mmc-hub-grid">
        @if ($this->getDailyRecordUrl())
            <button type="button" wire:click="mountAction('registoDiario')" class="mmc-hub-card text-left w-full cursor-pointer">
                <x-filament::icon icon="heroicon-o-clipboard-document-check" class="mmc-hub-card-icon" />
                <div class="mmc-hub-card-body">
                    <div class="mmc-hub-card-title">Registo Diário</div>
                    <div class="mmc-hub-card-sub">Consultar e criar registos diários das piscinas.</div>
                </div>
            </button>
        @endif

        @if ($this->getIncidentUrl())
            <a href="{{ $this->getIncidentUrl() }}" class="mmc-hub-card">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mmc-hub-card-icon" />
                <div class="mmc-hub-card-body">
                    <div class="mmc-hub-card-title">Incidentes</div>
                    <div class="mmc-hub-card-sub">Consultar e registar incidentes.</div>
                </div>
            </a>
        @endif
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
