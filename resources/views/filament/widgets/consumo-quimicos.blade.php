<x-filament-widgets::widget>
    <x-filament::section heading="Consumo de químicos por piscina (últimos 6 meses)" icon="heroicon-o-beaker">
        @php($payload = $this->getChartPayload())

        @if (empty($payload['datasets']))
            <p class="text-sm text-gray-500 dark:text-gray-400">Sem adições de químicos registadas neste período.</p>
        @else
            <div wire:ignore x-data="mmcBarChart({{ Illuminate\Support\Js::from($payload) }})" style="height: 320px;">
                <canvas x-ref="canvas"></canvas>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
