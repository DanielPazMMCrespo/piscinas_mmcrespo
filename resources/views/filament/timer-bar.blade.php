<div
    x-data="mmcTimerBar()"
    x-show="timers.length > 0"
    x-cloak
    class="fixed top-0 inset-x-0 z-50 flex flex-wrap items-center gap-2 px-3 py-2 bg-gray-900/95 dark:bg-gray-950/95 backdrop-blur text-white text-xs shadow-lg"
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
