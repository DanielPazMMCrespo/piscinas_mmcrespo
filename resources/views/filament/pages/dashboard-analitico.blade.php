<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6">
        @livewire(\App\Filament\Widgets\TempoRespostaWidget::class)
        @livewire(\App\Filament\Widgets\HeatmapConformidadeWidget::class)
        @livewire(\App\Filament\Widgets\ConsumoQuimicosWidget::class)
    </div>
</x-filament-panels::page>
