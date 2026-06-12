<x-filament-panels::page>
    {{-- Gráfico grande, ecrã todo. Reutiliza o widget do dashboard. --}}
    <div class="mmc-analise-grande">
        @livewire(\App\Filament\Widgets\CloroPhChartWidget::class)
    </div>

    <style>
        /* Nesta página os gráficos ganham mais altura para análise detalhada. */
        .mmc-analise-grande .mmc-grafico-canvas-wrap { height: 420px; }
        .mmc-analise-grande .mmc-canvas-alto { height: 520px; }
        @media (max-width: 640px) {
            .mmc-analise-grande .mmc-grafico-canvas-wrap { height: 300px; }
            .mmc-analise-grande .mmc-canvas-alto { height: 340px; }
        }
    </style>
</x-filament-panels::page>
