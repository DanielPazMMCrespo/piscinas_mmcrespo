<div class="flex-1 flex flex-col h-full px-4 pt-6">
    <header class="flex items-center justify-between mb-6 shrink-0">
        <h1 class="text-2xl font-bold tracking-tight text-white">Registo Diário</h1>
        <div class="px-3 py-1 rounded-full bg-slate-800 text-xs font-semibold text-slate-300">
            Piscina Principal
        </div>
    </header>

    @if (session()->has('success'))
        <div class="mb-4 p-4 rounded-2xl bg-green-500/20 border border-green-500/50 text-green-400 font-medium shrink-0">
            {{ session('success') }}
        </div>
    @endif

    <form wire:submit="save" class="flex flex-col flex-1 pb-4">
        <!-- Núcleo: 2x2 Grid para os 4 parâmetros principais -->
        <div class="grid grid-cols-2 gap-4 mb-6 shrink-0">
            <!-- pH -->
            <div class="flex flex-col space-y-1">
                <label for="ph" class="text-sm font-semibold text-slate-400 pl-1">pH</label>
                <div class="relative">
                    <input type="text" inputmode="decimal" id="ph" wire:model="ph" 
                           class="w-full h-20 text-center text-4xl font-bold bg-slate-900 border border-slate-700/50 rounded-3xl text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-shadow" 
                           placeholder="-" />
                </div>
            </div>
            
            <!-- Cloro Livre -->
            <div class="flex flex-col space-y-1">
                <label for="free_chlorine" class="text-sm font-semibold text-slate-400 pl-1">Cloro Livre</label>
                <div class="relative">
                    <input type="text" inputmode="decimal" id="free_chlorine" wire:model="free_chlorine" 
                           class="w-full h-20 text-center text-4xl font-bold bg-slate-900 border border-slate-700/50 rounded-3xl text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-shadow" 
                           placeholder="-" />
                </div>
            </div>

            <!-- Cloro Total -->
            <div class="flex flex-col space-y-1">
                <label for="total_chlorine" class="text-sm font-semibold text-slate-400 pl-1">Cloro Total</label>
                <div class="relative">
                    <input type="text" inputmode="decimal" id="total_chlorine" wire:model="total_chlorine" 
                           class="w-full h-20 text-center text-4xl font-bold bg-slate-900 border border-slate-700/50 rounded-3xl text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-shadow" 
                           placeholder="-" />
                </div>
            </div>

            <!-- Temperatura -->
            <div class="flex flex-col space-y-1">
                <label for="temperature" class="text-sm font-semibold text-slate-400 pl-1">Temp. (&deg;C)</label>
                <div class="relative">
                    <input type="text" inputmode="decimal" id="temperature" wire:model="temperature" 
                           class="w-full h-20 text-center text-4xl font-bold bg-slate-900 border border-slate-700/50 rounded-3xl text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-shadow" 
                           placeholder="-" />
                </div>
            </div>
        </div>

        <!-- Botão Expansível para Mais Parâmetros -->
        <button type="button" wire:click="toggleMore" class="flex items-center justify-center w-full py-4 text-sm font-medium text-blue-400 active:text-blue-300 active:scale-95 transition-all mb-4 shrink-0">
            @if($showMore)
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 mr-2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" />
                </svg>
                Ocultar parâmetros adicionais
            @else
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 mr-2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
                Mais parâmetros (Alcalinidade, etc...)
            @endif
        </button>

        <!-- Seção Expansível -->
        @if($showMore)
            <div class="flex flex-col space-y-4 mb-6 animate-in slide-in-from-top-4 fade-in duration-200">
                <div class="grid grid-cols-2 gap-4">
                    <div class="flex flex-col space-y-1">
                        <label for="alkalinity" class="text-xs font-medium text-slate-400 pl-1">Alcalinidade</label>
                        <input type="text" inputmode="decimal" id="alkalinity" wire:model="alkalinity" 
                               class="w-full h-14 text-center text-xl font-semibold bg-slate-900 border border-slate-800 rounded-2xl text-white placeholder-slate-600 focus:outline-none focus:ring-1 focus:ring-blue-500" 
                               placeholder="-" />
                    </div>
                    <div class="flex flex-col space-y-1">
                        <label for="cyanuric_acid" class="text-xs font-medium text-slate-400 pl-1">Ácido Cianúrico</label>
                        <input type="text" inputmode="decimal" id="cyanuric_acid" wire:model="cyanuric_acid" 
                               class="w-full h-14 text-center text-xl font-semibold bg-slate-900 border border-slate-800 rounded-2xl text-white placeholder-slate-600 focus:outline-none focus:ring-1 focus:ring-blue-500" 
                               placeholder="-" />
                    </div>
                </div>
                <div class="flex flex-col space-y-1">
                    <label for="water_meter" class="text-xs font-medium text-slate-400 pl-1">Contador de Água</label>
                    <input type="text" inputmode="decimal" id="water_meter" wire:model="water_meter" 
                           class="w-full h-14 text-center text-xl font-semibold bg-slate-900 border border-slate-800 rounded-2xl text-white placeholder-slate-600 focus:outline-none focus:ring-1 focus:ring-blue-500" 
                           placeholder="-" />
                </div>
            </div>
        @endif

        <!-- Botão Submeter Fixo -->
        <div class="fixed bottom-[calc(env(safe-area-inset-bottom)+4rem)] left-0 right-0 max-w-md mx-auto w-full px-4 pt-8 pb-4 bg-gradient-to-t from-black via-black/80 to-transparent z-40 pointer-events-none">
            <button type="submit" 
                    class="w-full h-16 rounded-full bg-blue-600 text-white font-bold text-lg tracking-wide shadow-lg shadow-blue-500/30 active:scale-95 active:bg-blue-700 transition-all flex items-center justify-center pointer-events-auto">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6 mr-2">
                    <path fill-rule="evenodd" d="M19.916 4.626a.75.75 0 0 1 .208 1.04l-9 13.5a.75.75 0 0 1-1.154.114l-6-6a.75.75 0 0 1 1.06-1.06l5.353 5.353 8.493-12.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd" />
                </svg>
                GRAVAR REGISTO
            </button>
        </div>
        <!-- Spacer extra na form para garantir scroll por baixo do botão fixo -->
        <div class="h-28 shrink-0"></div>
    </form>
</div>
