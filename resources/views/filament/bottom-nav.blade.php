<!-- Premium Floating Mobile Navigation -->
<div class="fixed bottom-4 left-4 right-4 z-50 md:hidden pb-safe" id="mmc-bottom-nav">
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-[0_10px_40px_rgba(0,0,0,0.15)] dark:shadow-[0_10px_40px_rgba(0,0,0,0.6)] px-2 py-2 flex justify-around items-center relative" style="border-radius: 2rem;">
        
        <!-- Subtle gradient glow behind icons -->
        <div class="absolute inset-0 bg-gradient-to-r from-sky-500/5 via-transparent to-sky-500/5 pointer-events-none overflow-hidden" style="border-radius: 2rem;"></div>

        <a href="/admin" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 text-slate-400 hover:text-[#004c8c] dark:hover:text-sky-400 z-10 py-1">
            <x-heroicon-o-home class="w-6 h-6 mb-1 transition-colors" />
            <span class="text-[10px] font-medium tracking-wide">Início</span>
        </a>

        @can('create', \App\Models\DailyRecord::class)
            <a href="/admin/daily-records/create" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 z-10">
                <div class="text-white p-3 transition-colors" style="background-color: #004c8c; box-shadow: 0 10px 25px -5px rgba(0, 76, 140, 0.4); border-radius: 1rem; margin-top: -2rem; margin-bottom: 0.25rem;">
                    <x-heroicon-o-plus class="w-6 h-6" />
                </div>
                <span class="text-[10px] font-bold tracking-wide" style="color: #004c8c;">Registar</span>
            </a>
        @else
            <a href="/admin/daily-records" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 text-slate-400 hover:text-[#004c8c] dark:hover:text-sky-400 z-10 py-1">
                <x-heroicon-o-list-bullet class="w-6 h-6 mb-1" />
                <span class="text-[10px] font-medium tracking-wide">Registos</span>
            </a>
        @endcan

        <a href="/admin/analise-parametros" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-300 active:scale-90 text-slate-400 hover:text-[#004c8c] dark:hover:text-sky-400 z-10 py-1">
            <x-heroicon-o-chart-bar class="w-6 h-6 mb-1 transition-colors" />
            <span class="text-[10px] font-medium tracking-wide">Análise</span>
        </a>
    </div>
</div>

<style>
    .dark #mmc-bottom-nav > div {
        background-color: #0f172a !important;
        border-color: #1e293b !important;
    }
    .dark #mmc-bottom-nav span {
        color: #94a3b8;
    }
    .dark #mmc-bottom-nav a:hover span, .dark #mmc-bottom-nav a:hover svg {
        color: #38bdf8 !important;
    }
    /* Prevent content from hiding behind floating nav */
    @media (max-width: 767px) {
        .fi-main {
            padding-bottom: 6.5rem !important;
        }

        body:has(.fi-modal-open) #mmc-bottom-nav {
            display: none;
            opacity: 0;
            pointer-events: none;
        }
    }
</style>
