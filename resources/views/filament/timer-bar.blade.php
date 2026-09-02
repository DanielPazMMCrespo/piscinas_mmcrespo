<div
    x-data="mmcTimerBar()"
    x-show="timers.length > 0"
    x-cloak
    {{--
        Fica no fundo, não no topo.

        Em `top-0 z-50` a barra assentava por cima da `.fi-topbar` (z-30) e tapava
        o botão de abrir a sidebar em telemóvel — um timer esquecido trancava a
        navegação da app inteira. No fundo nunca tapa nada de navegação.

        Em telemóvel sobe acima da bottom-nav (que arranca em bottom-4 e tem
        64 px); em desktop assenta na margem inferior, onde não há nav.
        A aparência não muda: mesma cor, mesmo tipo, mesmo desfoque.
    --}}
    class="fixed inset-x-0 bottom-[calc(5.75rem+env(safe-area-inset-bottom))] md:bottom-0 z-40 flex flex-wrap items-center gap-2 px-3 py-2 bg-gray-900/95 dark:bg-gray-950/95 backdrop-blur text-white text-xs shadow-lg"
>
    <template x-for="timer in timers" :key="timer.key">
        <button
            @click="navegar(timer.statePath, timer.poolId)"
            class="flex items-center gap-2 rounded-lg px-2.5 py-1 transition-all hover:opacity-100 opacity-90 cursor-pointer"
            x-bind:class="timer.isExceeded ? 'bg-danger-600/90 hover:bg-danger-700' : 'bg-white/10 hover:bg-white/20'"
            :title="`Ir para ${timer.poolNome} - ${timer.fase}`"
        >
            <span x-text="timer.poolNome" class="font-medium"></span>
            <span x-text="timer.fase" class="opacity-80"></span>
            <span x-text="formatar(timer.remainingSeconds)" class="font-mono font-bold tracking-wider"></span>
        </button>
    </template>
</div>
