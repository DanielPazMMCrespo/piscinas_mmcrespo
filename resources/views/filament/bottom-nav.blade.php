<!-- Premium Floating Mobile Navigation -->
<div class="fixed bottom-4 left-4 right-4 z-50 md:hidden pb-safe" id="mmc-bottom-nav">
    <div class="bg-white/85 dark:bg-slate-900/85 backdrop-blur-2xl border border-white/20 dark:border-slate-700/50 rounded-3xl shadow-[0_8px_32px_rgba(0,0,0,0.08)] dark:shadow-[0_8px_32px_rgba(0,0,0,0.4)] px-2 py-2 flex justify-around items-center relative overflow-hidden">
        
        <!-- Subtle gradient glow behind icons -->
        <div class="absolute inset-0 bg-gradient-to-r from-primary-500/5 via-transparent to-primary-500/5 dark:from-primary-500/10 dark:to-primary-500/10 pointer-events-none"></div>

        <a href="/admin" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 z-10 py-1">
            <x-heroicon-o-home class="w-6 h-6 mb-1 transition-colors" />
            <span class="text-[10px] font-medium tracking-wide">Início</span>
        </a>

        @can('create', \App\Models\DailyRecord::class)
            <a href="/admin/daily-records/create" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 z-10">
                <div class="bg-primary-600 text-white rounded-2xl p-3 shadow-lg shadow-primary-600/30 -mt-8 mb-1 group-hover:bg-primary-500 transition-colors">
                    <x-heroicon-o-plus class="w-6 h-6" />
                </div>
                <span class="text-[10px] font-medium tracking-wide text-primary-600 dark:text-primary-400">Registar</span>
            </a>
        @else
            <a href="/admin/daily-records" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 z-10 py-1">
                <x-heroicon-o-list-bullet class="w-6 h-6 mb-1" />
                <span class="text-[10px] font-medium tracking-wide">Registos</span>
            </a>
        @endcan

        <a href="/admin/analise-parametros" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 text-gray-400 hover:text-primary-600 dark:hover:text-primary-400 z-10 py-1">
            <x-heroicon-o-chart-bar class="w-6 h-6 mb-1 transition-colors" />
            <span class="text-[10px] font-medium tracking-wide">Análise</span>
        </a>
    </div>
</div>

<style>
    /* Prevent content from hiding behind floating nav */
    @media (max-width: 767px) {
        body {
            padding-bottom: 6rem !important;
        }

        body:has(.fi-modal-open) #mmc-bottom-nav {
            display: none;
            opacity: 0;
            pointer-events: none;
        }
    }
</style>
