<x-filament-panels::page>
    {{-- Gráfico grande, ecrã todo. Reutiliza o widget do dashboard. --}}
    <div class="mmc-analise-grande">
        @livewire(\App\Filament\Widgets\CloroPhChartWidget::class)
    </div>

    @unless (auth()->user()?->hasRole(\App\Constants\UserRole::NADADOR_SALVADOR))
        <div class="grid grid-cols-1 gap-6 mt-6">
            {{-- Resposta direta à pergunta de auditoria, antes dos gráficos de apoio. --}}
            @livewire(\App\Filament\Widgets\ViolacoesPeriodoWidget::class)
            @livewire(\App\Filament\Widgets\ScoreConformidadeWidget::class)
            @livewire(\App\Filament\Widgets\HeatmapConformidadeWidget::class)
            @livewire(\App\Filament\Widgets\EstabilidadeMedicoesWidget::class)
            @livewire(\App\Filament\Widgets\ConsumoQuimicosWidget::class)
        </div>
    @endunless

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
