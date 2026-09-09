<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-2">
                <x-heroicon-o-chat-bubble-left-right class="w-5 h-5 text-primary-500" />
                <span>Conversa & Histórico</span>
            </div>
        </x-slot>

        <div 
            x-data 
            x-init="$el.scrollTop = $el.scrollHeight"
            class="mmc-incident-chat space-y-3 mb-4 max-h-96 overflow-y-auto pr-1"
        >
            @forelse ($record?->mensagens ?? [] as $mensagem)
                @if ($mensagem->eSistema())
                    <div class="flex justify-center my-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 dark:bg-gray-800 border border-gray-200/80 dark:border-gray-700/60 px-3.5 py-1 text-xs font-medium text-gray-600 dark:text-gray-300 shadow-xs">
                            <x-heroicon-m-information-circle class="w-3.5 h-3.5 text-gray-400" />
                            <span>{{ $mensagem->texto }}</span>
                            <span class="text-gray-400 dark:text-gray-500">· {{ $mensagem->created_at->format('d/m H:i') }}</span>
                        </span>
                    </div>
                @else
                    <div class="flex {{ $mensagem->user_id === auth()->id() ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[80%] sm:max-w-[70%] rounded-2xl px-3.5 py-2.5 shadow-xs {{ $mensagem->user_id === auth()->id() ? 'bg-primary-600 text-white rounded-br-xs' : 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100 rounded-bl-xs border border-gray-200/60 dark:border-gray-700/50' }}">
                            @if ($mensagem->user_id !== auth()->id())
                                <p class="text-xs font-semibold text-primary-600 dark:text-primary-400 mb-1">{{ $mensagem->autor->name }}</p>
                            @endif
                            <p class="text-sm leading-relaxed whitespace-pre-wrap select-text">{{ $mensagem->texto }}</p>
                            <p class="text-[10px] {{ $mensagem->user_id === auth()->id() ? 'text-white/75 text-right' : 'text-gray-400 dark:text-gray-500' }} mt-1">
                                {{ $mensagem->created_at->format('d/m H:i') }}
                            </p>
                        </div>
                    </div>
                @endif
            @empty
                <div class="text-center py-6 text-sm text-gray-400 dark:text-gray-500">
                    <x-heroicon-o-chat-bubble-bottom-center-text class="w-8 h-8 mx-auto mb-1 text-gray-300 dark:text-gray-600" />
                    <p>Ainda sem mensagens. Escreva abaixo para iniciar a comunicação.</p>
                </div>
            @endforelse
        </div>

        <form 
            wire:submit.prevent="enviarMensagem" 
            class="flex items-end gap-2 pt-2 border-t border-gray-200/60 dark:border-gray-700/60"
        >
            <label for="incident_chat_texto" class="sr-only">Escreva uma mensagem</label>
            <textarea
                id="incident_chat_texto"
                name="texto"
                wire:model="texto"
                rows="2"
                x-data
                x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.enviarMensagem(); }"
                class="fi-input flex-1 rounded-xl border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-3 py-2 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500"
                placeholder="Escreva uma mensagem (Enter para enviar)..."
            ></textarea>
            <x-filament::button 
                type="submit" 
                icon="heroicon-m-paper-airplane"
                wire:loading.attr="disabled"
            >
                Enviar
            </x-filament::button>
        </form>
    </x-filament::section>
</x-filament-widgets::widget>
