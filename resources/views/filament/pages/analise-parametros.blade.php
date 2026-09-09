<x-filament-panels::page>
    @php
        $isNS = auth()->user()?->hasRole(\App\Constants\UserRole::NADADOR_SALVADOR);
    @endphp

    @if ($isNS)
        {{-- Nadador Salvador: Apenas Gráfico da Piscina Atribuída --}}
        <div class="mmc-analise-grande">
            @livewire(\App\Filament\Widgets\CloroPhChartWidget::class)
        </div>
    @else
        <div x-data="{ tab: 'evolucao' }" class="space-y-6">
            {{-- Navegação Segmentada Apple HIG --}}
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="inline-flex bg-slate-200/70 dark:bg-slate-800/80 p-1 rounded-2xl shadow-inner border border-slate-200/50 dark:border-slate-700/50" role="tablist" aria-label="Modo de Análise">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="tab === 'evolucao'"
                        @click="tab = 'evolucao'; $nextTick(() => window.dispatchEvent(new Event('resize')))"
                        :class="tab === 'evolucao'
                            ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm font-semibold'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium'"
                        class="flex items-center gap-2 px-5 py-2.5 text-sm rounded-xl transition-all duration-150 active:scale-95 focus:outline-none"
                    >
                        <x-heroicon-o-chart-bar class="w-4 h-4 text-primary-500" />
                        <span>Evolução &amp; Gráficos</span>
                    </button>

                    <button
                        type="button"
                        role="tab"
                        :aria-selected="tab === 'conformidade'"
                        @click="tab = 'conformidade'"
                        :class="tab === 'conformidade'
                            ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm font-semibold'
                            : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium'"
                        class="flex items-center gap-2 px-5 py-2.5 text-sm rounded-xl transition-all duration-150 active:scale-95 focus:outline-none"
                    >
                        <x-heroicon-o-shield-check class="w-4 h-4 text-emerald-500" />
                        <span>Auditoria DGS (CN 14/DA)</span>
                    </button>
                </div>

                <div class="text-xs text-slate-500 dark:text-slate-400 hidden sm:block">
                    <span x-show="tab === 'evolucao'">Análise temporal em alta resolução</span>
                    <span x-show="tab === 'conformidade'">Painel de conformidade e violações legais</span>
                </div>
            </div>

            {{-- Aba 1: Gráfico e Evolução --}}
            <div x-show="tab === 'evolucao'" x-cloak class="mmc-analise-grande">
                @livewire(\App\Filament\Widgets\CloroPhChartWidget::class)
            </div>

            {{-- Aba 2: Auditoria DGS / Conformidade Legal --}}
            <div x-show="tab === 'conformidade'" x-cloak class="space-y-6">
                @livewire(\App\Filament\Widgets\ScoreConformidadeWidget::class)
                @livewire(\App\Filament\Widgets\HeatmapConformidadeWidget::class)
                @livewire(\App\Filament\Widgets\ViolacoesPeriodoWidget::class)
            </div>
        </div>
    @endif

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
