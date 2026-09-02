<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $defaultMinutes = $getDefaultState() ?? 3;
    @endphp

    <div x-data="countdownTimer('{{ $getStatePath() }}', {{ $defaultMinutes * 60 }})"
         x-on:mmc-timer-terminar.window="if ($event.detail.statePath === statePath) terminar()"
         data-mmc-timer="{{ $getStatePath() }}"
         class="flex flex-col sm:flex-row items-center justify-between gap-4 p-4 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-sm w-full transition-all">
        
        <!-- Ajustes de tempo -->
        <div class="flex items-center gap-2 w-full sm:w-auto justify-center sm:justify-start">
            <x-filament::button
                color="gray"
                icon="heroicon-m-minus"
                size="sm"
                x-on:click="adjustTime(-60)"
                x-bind:disabled="isRunning"
                tooltip="Menos 1 minuto"
                class="h-10 w-10 flex items-center justify-center"
            />
            <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 px-2 min-w-[50px] text-center" x-text="Math.floor(initialSeconds / 60) + ' min'"></span>
            <x-filament::button
                color="gray"
                icon="heroicon-m-plus"
                size="sm"
                x-on:click="adjustTime(60)"
                x-bind:disabled="isRunning"
                tooltip="Mais 1 minuto"
                class="h-10 w-10 flex items-center justify-center"
            />
        </div>

        <!-- Cronómetro e Botões de Ação -->
        <div class="flex items-center gap-4 w-full sm:w-auto justify-between sm:justify-end flex-1">
            <div class="flex items-center gap-2">
                <div class="text-3xl font-mono tracking-wider font-bold"
                     x-bind:class="{ 'text-danger-600 dark:text-danger-400': isExceeded, 'text-gray-900 dark:text-white': !isExceeded }"
                     x-text="formattedTime">
                </div>

                <template x-if="isExceeded">
                    <x-filament::badge color="danger">
                        Excedido
                    </x-filament::badge>
                </template>
            </div>

            <x-filament::button
                x-on:click="resetTimer"
                color="gray"
                icon="heroicon-m-arrow-path"
                class="h-10 min-w-[100px] flex items-center justify-center"
                x-show="remainingSeconds !== initialSeconds"
            >
                Reiniciar
            </x-filament::button>

            <x-filament::button
                x-on:click="toggleTimer"
                x-bind:color="isRunning ? 'danger' : 'primary'"
                x-bind:icon="isRunning ? 'heroicon-m-pause' : 'heroicon-m-play'"
                class="h-10 min-w-[100px] flex items-center justify-center"
            >
                <span x-text="isRunning ? 'Pausar' : (remainingSeconds === initialSeconds ? 'Iniciar' : 'Retomar')"></span>
            </x-filament::button>
        </div>

        <input type="hidden" {{ $applyStateBindingModifiers('wire:model') }}="{{ $getStatePath() }}" x-model="remainingSeconds" />
    </div>
</x-dynamic-component>

