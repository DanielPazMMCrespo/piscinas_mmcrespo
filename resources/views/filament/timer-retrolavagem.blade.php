<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $defaultMinutes = $getDefaultState() ?? 3;
    @endphp

    <div x-data="countdownTimer('{{ $getStatePath() }}', {{ $defaultMinutes * 60 }})"
         class="flex items-center gap-4 bg-gray-50 dark:bg-gray-800/50 p-4 rounded-xl border border-gray-200 dark:border-gray-700 w-full">
        
        <div class="flex items-center gap-2">
            <x-filament::button
                color="gray"
                icon="heroicon-m-minus"
                size="sm"
                x-on:click="adjustTime(-60)"
                x-bind:disabled="isRunning"
                tooltip="Menos 1 minuto"
            />
            <span class="text-sm font-medium" x-text="Math.floor(initialSeconds / 60) + ' min'"></span>
            <x-filament::button
                color="gray"
                icon="heroicon-m-plus"
                size="sm"
                x-on:click="adjustTime(60)"
                x-bind:disabled="isRunning"
                tooltip="Mais 1 minuto"
            />
        </div>

        <div class="text-3xl font-mono tracking-wider ml-auto"
             x-bind:class="{ 'text-danger-600 dark:text-danger-400': isExceeded, 'text-gray-900 dark:text-white': !isExceeded }"
             x-text="formattedTime">
        </div>

        <template x-if="isExceeded">
            <x-filament::badge color="danger" class="ml-2">
                Excedido
            </x-filament::badge>
        </template>

        <x-filament::button
            x-on:click="toggleTimer"
            x-bind:color="isRunning ? 'danger' : 'primary'"
            x-bind:icon="isRunning ? 'heroicon-m-pause' : 'heroicon-m-play'"
            class="ml-4"
        >
            <span x-text="isRunning ? 'Pausar' : (remainingSeconds === initialSeconds ? 'Iniciar' : 'Retomar')"></span>
        </x-filament::button>

        <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}" x-model="remainingSeconds" />
    </div>
</x-dynamic-component>
