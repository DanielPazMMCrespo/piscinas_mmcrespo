<div
    x-data="mmcTimerBar()"
    x-show="timers.length > 0"
    x-cloak
    class="mmc-timer-bar"
>
    <template x-for="timer in timers" :key="timer.key">
        <div
            class="flex items-center gap-1 rounded-lg py-1 pl-2.5 pr-1"
            {{-- `danger` nao e uma cor do Tailwind desta app (so existe no CSS do
                 Filament, e mesmo la sem utilitarios de fundo): o `bg-danger-600/90`
                 que aqui estava nao gerava regra nenhuma e um timer excedido ficava
                 visualmente igual a um a contar. --}}
            x-bind:class="timer.isExceeded ? 'bg-red-600' : 'bg-white/10'"
        >
            <button
                type="button"
                @click="navegar(timer)"
                class="flex items-center gap-2 opacity-90 transition-opacity hover:opacity-100 cursor-pointer"
                :title="`Ir para ${timer.poolNome} — ${timer.fase}`"
            >
                <span x-text="timer.poolNome" class="font-medium"></span>
                <span x-text="timer.fase" class="opacity-80"></span>
                <span x-text="formatar(timer.remainingSeconds)" class="font-mono font-bold tracking-wider"></span>
            </button>

            {{-- Sem isto um timer esquecido não tinha como ser fechado: ficava
                 visível em todas as páginas do painel até 30 min depois de expirar. --}}
            <button
                type="button"
                @click="parar(timer)"
                class="flex h-9 w-9 items-center justify-center rounded-md text-white/70 transition-colors hover:bg-white/20 hover:text-white cursor-pointer"
                :aria-label="`Terminar ${timer.fase} de ${timer.poolNome}`"
                :title="`Terminar ${timer.fase} de ${timer.poolNome}`"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    </template>
</div>
