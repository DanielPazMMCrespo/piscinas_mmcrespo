{{-- Navegação inferior (telemóvel). O FAB leva a última piscina do utilizador:
     sem isso o atalho mais visível era o caminho mais lento (escolher instalação
     antes do primeiro campo). --}}
@php
    $ultimaPiscinaId = \Illuminate\Support\Facades\Cache::remember(
        'ultima_piscina_utilizador_'.auth()->id(),
        now()->addMinutes(10),
        fn () => \App\Models\DailyRecord::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('registado_em')
            ->value('pool_id')
    );
    $isNS = auth()->user()?->hasRole(\App\Constants\UserRole::NADADOR_SALVADOR) ?? false;
    $urlRegistar = '/admin/daily-records/create'.(! $isNS && $ultimaPiscinaId ? '?pool='.$ultimaPiscinaId.'&quick=1' : '');
@endphp

<div class="fixed bottom-4 left-4 right-4 z-50 md:hidden pb-safe" id="mmc-bottom-nav" style="pointer-events: none;">
    <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 shadow-[0_10px_40px_rgba(0,0,0,0.15)] dark:shadow-[0_10px_40px_rgba(0,0,0,0.6)] px-3 py-2 flex justify-around items-end relative" style="border-radius: 2rem; min-height: 64px; pointer-events: auto;">
        
        <!-- Subtle gradient glow behind icons -->
        <div class="absolute inset-0 bg-gradient-to-r from-sky-500/5 via-transparent to-sky-500/5 pointer-events-none overflow-hidden" style="border-radius: 2rem;"></div>

        <!-- Left Item: Início -->
        <a href="/admin" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-200 active:scale-90 text-slate-400 hover:text-[#004c8c] dark:hover:text-sky-400 z-10 py-1">
            <x-heroicon-o-home class="w-6 h-6 mb-1 transition-colors" />
            <span class="text-[10px] font-medium tracking-wide">Início</span>
        </a>

        <!-- Center Item: Registar (Floating Action Button) -->
        @can('create', \App\Models\DailyRecord::class)
            <a href="{{ $urlRegistar }}" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-200 active:scale-95 z-10 py-1">
                <div class="flex items-center justify-center text-white shadow-lg transition-transform group-hover:scale-105" 
                     style="width: 52px; height: 52px; background-color: #004c8c; border-radius: 50%; box-shadow: 0 8px 20px rgba(0, 76, 140, 0.4); margin-top: -28px; margin-bottom: 2px; border: 3px solid #ffffff;"
                     id="mmc-fab-circle">
                    <x-heroicon-o-plus class="w-7 h-7" />
                </div>
                <span class="text-[10px] font-bold tracking-wide" style="color: #004c8c;" id="mmc-fab-text">Registar</span>
            </a>
        @else
            <a href="/admin/daily-records" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-200 active:scale-90 text-slate-400 hover:text-[#004c8c] dark:hover:text-sky-400 z-10 py-1">
                <x-heroicon-o-list-bullet class="w-6 h-6 mb-1" />
                <span class="text-[10px] font-medium tracking-wide">Registos</span>
            </a>
        @endcan

        <!-- Right Item: Registos (quando há FAB de registar) ou Análise -->
        @can('create', \App\Models\DailyRecord::class)
            <a href="/admin/daily-records" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-200 active:scale-90 text-slate-400 hover:text-[#004c8c] dark:hover:text-sky-400 z-10 py-1">
                <x-heroicon-o-clipboard-document-list class="w-6 h-6 mb-1 transition-colors" />
                <span class="text-[10px] font-medium tracking-wide">Registos</span>
            </a>
        @else
            <a href="/admin/analise-parametros" class="flex flex-col items-center justify-center w-full relative group transition-transform duration-200 active:scale-90 text-slate-400 hover:text-[#004c8c] dark:hover:text-sky-400 z-10 py-1">
                <x-heroicon-o-chart-bar class="w-6 h-6 mb-1 transition-colors" />
                <span class="text-[10px] font-medium tracking-wide">Análise</span>
            </a>
        @endcan
    </div>
</div>

<style>
    #mmc-bottom-nav {
        position: fixed !important;
        bottom: 1rem !important;
        left: 1rem !important;
        right: 1rem !important;
        z-index: 9999 !important;
    }
    .dark #mmc-bottom-nav > div {
        background-color: #0f172a !important;
        border-color: #1e293b !important;
    }
    .dark #mmc-fab-circle {
        border-color: #0f172a !important;
    }
    .dark #mmc-fab-text {
        color: #38bdf8 !important;
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
            padding-bottom: 7rem !important;
        }

        body:has(.fi-modal-open) #mmc-bottom-nav {
            display: none;
            opacity: 0;
            pointer-events: none;
        }
    }
</style>
