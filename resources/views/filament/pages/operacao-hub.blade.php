<x-filament-panels::page>
    <div class="mmc-hub-grid">
        @if ($this->getDailyRecordUrl())
            <a href="{{ $this->getDailyRecordUrl() }}" class="mmc-hub-card">
                <x-filament::icon icon="heroicon-o-clipboard-document-check" class="mmc-hub-card-icon" />
                <div class="mmc-hub-card-body">
                    <div class="mmc-hub-card-title">Registo Diário</div>
                    <div class="mmc-hub-card-sub">Consultar e criar registos diários das piscinas.</div>
                </div>
            </a>
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
</x-filament-panels::page>
