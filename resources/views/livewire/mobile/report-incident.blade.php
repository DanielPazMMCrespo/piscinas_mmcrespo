<div class="flex-1 flex flex-col h-full px-4 pt-6">
    <header class="flex items-center justify-between mb-6 shrink-0">
        <h1 class="text-2xl font-bold tracking-tight text-white">Novo Incidente</h1>
    </header>

    @if (session()->has('success'))
        <div class="mb-4 p-4 rounded-2xl bg-green-500/20 border border-green-500/50 text-green-400 font-medium shrink-0">
            {{ session('success') }}
        </div>
    @endif

    <form wire:submit="save" class="flex flex-col flex-1 pb-4">
        
        <!-- Tipologia (Substitui Select) -->
        <div class="mb-6 shrink-0">
            <label class="text-sm font-semibold text-slate-400 pl-1 mb-2 block">Tipo de Incidente</label>
            <div class="grid grid-cols-2 gap-3">
                <!-- Fuga -->
                <button type="button" wire:click="selectType('fuga')" 
                        class="flex flex-col items-center justify-center p-4 rounded-3xl border-2 transition-all {{ $type === 'fuga' ? 'border-blue-500 bg-blue-500/10' : 'border-slate-800 bg-slate-900' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 mb-2 {{ $type === 'fuga' ? 'text-blue-400' : 'text-slate-400' }}">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 2.25c-1.892 2.97-4.5 5.85-4.5 9A4.5 4.5 0 0 0 12 15.75 4.5 4.5 0 0 0 16.5 11.25c0-3.15-2.608-6.03-4.5-9Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15.75c1.243 0 2.25 1.007 2.25 2.25S13.243 20.25 12 20.25s-2.25-1.007-2.25-2.25 1.007-2.25 2.25-2.25Z" />
                    </svg>
                    <span class="font-semibold {{ $type === 'fuga' ? 'text-blue-400' : 'text-slate-300' }}">Fuga</span>
                </button>

                <!-- Equipamento -->
                <button type="button" wire:click="selectType('equipamento')" 
                        class="flex flex-col items-center justify-center p-4 rounded-3xl border-2 transition-all {{ $type === 'equipamento' ? 'border-orange-500 bg-orange-500/10' : 'border-slate-800 bg-slate-900' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 mb-2 {{ $type === 'equipamento' ? 'text-orange-400' : 'text-slate-400' }}">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0 0 15 0m-15 0a7.5 7.5 0 1 1 15 0m-15 0H3m16.5 0H21m-1.5 0H12m-8.457 3.077 1.41-.513m14.095-5.13 1.41-.513M5.106 17.785l1.15-.964m11.49-9.642 1.149-.964M7.501 19.79l.75-1.3m7.5-12.979.75-1.3m-10.609 2.285.513-1.41m10.26 14.095.513-1.41m-14.15-5.115.964-1.15m9.642-11.49.964-1.15M19.79 16.5l-1.3-.75m-12.979-7.5-1.3-.75" />
                        <circle cx="12" cy="12" r="3" />
                    </svg>
                    <span class="font-semibold {{ $type === 'equipamento' ? 'text-orange-400' : 'text-slate-300' }}">Motor/Eq.</span>
                </button>

                <!-- Qualidade -->
                <button type="button" wire:click="selectType('qualidade')" 
                        class="flex flex-col items-center justify-center p-4 rounded-3xl border-2 transition-all {{ $type === 'qualidade' ? 'border-emerald-500 bg-emerald-500/10' : 'border-slate-800 bg-slate-900' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 mb-2 {{ $type === 'qualidade' ? 'text-emerald-400' : 'text-slate-400' }}">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 0 1-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 0 1 4.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0 1 12 15a9.065 9.065 0 0 1-6.23-.715m0 0L4.2 15.3m15.6 0v1.47a2.25 2.25 0 0 1-2.25 2.25H6.45a2.25 2.25 0 0 1-2.25-2.25v-1.47" />
                    </svg>
                    <span class="font-semibold {{ $type === 'qualidade' ? 'text-emerald-400' : 'text-slate-300' }}">Qualidade</span>
                </button>

                <!-- Outro -->
                <button type="button" wire:click="selectType('outro')" 
                        class="flex flex-col items-center justify-center p-4 rounded-3xl border-2 transition-all {{ $type === 'outro' ? 'border-purple-500 bg-purple-500/10' : 'border-slate-800 bg-slate-900' }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 mb-2 {{ $type === 'outro' ? 'text-purple-400' : 'text-slate-400' }}">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM12.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0ZM18.75 12a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z" />
                    </svg>
                    <span class="font-semibold {{ $type === 'outro' ? 'text-purple-400' : 'text-slate-300' }}">Outro</span>
                </button>
            </div>
            @error('type') <span class="text-red-400 text-xs mt-1">{{ $message }}</span> @enderror
        </div>

        <!-- Descrição Gigante -->
        <div class="mb-4 shrink-0">
            <div class="relative">
                <textarea wire:model="description" id="description" rows="4" 
                          class="w-full bg-slate-900 border border-slate-800 rounded-3xl p-5 text-xl font-medium text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none transition-shadow" 
                          placeholder="O que se passa?"></textarea>
            </div>
            @error('description') <span class="text-red-400 text-xs mt-1">{{ $message }}</span> @enderror
        </div>

        <!-- Foto -->
        <div class="mb-4 shrink-0">
            <input type="file" id="photo" wire:model="photo" accept="image/*" class="hidden">
            <label for="photo" class="flex items-center justify-center w-full p-4 rounded-3xl border-2 border-dashed border-slate-700 bg-slate-800/50 text-slate-300 font-medium active:scale-95 active:bg-slate-800 transition-all cursor-pointer">
                @if ($photo)
                    <div class="flex items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 mr-2 text-green-400">
                            <path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd" />
                        </svg>
                        Foto anexada
                    </div>
                @else
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 mr-2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z" />
                    </svg>
                    Tirar Foto
                @endif
            </label>
        </div>

        <!-- Data/Hora (Escondida por defeito) -->
        <div class="mb-4 shrink-0">
            @if(!$showDateEditor)
                <button type="button" wire:click="toggleDateEditor" class="text-sm font-medium text-slate-500 active:text-slate-300">
                    Ocorreu agora? <span class="underline">Alterar data</span>
                </button>
            @else
                <div class="animate-in fade-in slide-in-from-top-2">
                    <label class="text-sm font-semibold text-slate-400 pl-1 mb-1 block">Data e Hora</label>
                    <input type="datetime-local" wire:model="occurred_at" 
                           class="w-full h-14 bg-slate-900 border border-slate-800 rounded-2xl px-4 text-white font-medium focus:outline-none focus:ring-1 focus:ring-blue-500" />
                </div>
            @endif
        </div>

        <!-- Botão Submeter Fixo (Sticky viewport) -->
        <div class="fixed bottom-[calc(env(safe-area-inset-bottom)+4rem)] left-0 right-0 max-w-md mx-auto w-full px-4 pt-8 pb-4 bg-gradient-to-t from-black via-black/80 to-transparent z-40 pointer-events-none">
            <button type="submit" 
                    class="w-full h-16 rounded-full bg-red-600 text-white font-bold text-lg tracking-wide shadow-lg shadow-red-600/30 active:scale-95 active:bg-red-700 transition-all flex items-center justify-center pointer-events-auto">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 mr-2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3Z" />
                </svg>
                REPORTAR INCIDENTE
            </button>
        </div>
        
        <!-- Spacer extra na form para scroll por baixo do botão fixo -->
        <div class="h-32 shrink-0"></div>
    </form>
</div>
