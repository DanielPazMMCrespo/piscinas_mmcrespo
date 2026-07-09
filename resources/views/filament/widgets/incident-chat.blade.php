<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Conversa</x-slot>

        <div class="mmc-incident-chat space-y-3 mb-4 max-h-96 overflow-y-auto">
            @forelse ($record?->mensagens ?? [] as $mensagem)
                @if ($mensagem->eSistema())
                    <div class="text-center">
                        <span class="inline-block rounded-full bg-gray-100 dark:bg-gray-800 px-3 py-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $mensagem->texto }} · {{ $mensagem->created_at->format('d/m H:i') }}
                        </span>
                    </div>
                @else
                    <div class="flex {{ $mensagem->user_id === auth()->id() ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[75%] rounded-lg px-3 py-2 {{ $mensagem->user_id === auth()->id() ? 'bg-primary-600 text-white' : 'bg-gray-100 dark:bg-gray-800' }}">
                            <p class="text-xs opacity-75 mb-0.5">{{ $mensagem->autor->name }}</p>
                            <p class="text-sm">{{ $mensagem->texto }}</p>
                            <p class="text-xs opacity-60 mt-0.5">{{ $mensagem->created_at->format('d/m H:i') }}</p>
                        </div>
                    </div>
                @endif
            @empty
                <p class="text-sm text-gray-500">Ainda sem mensagens.</p>
            @endforelse
        </div>

        <form wire:submit.prevent="enviarMensagem" class="flex gap-2">
            <textarea
                wire:model="texto"
                rows="2"
                class="fi-input flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900"
                placeholder="Escreva uma mensagem..."
            ></textarea>
            <x-filament::button type="submit">Enviar</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-widgets::widget>
