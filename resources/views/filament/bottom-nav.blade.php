<div class="fixed bottom-0 left-0 right-0 z-10 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 pb-safe md:hidden flex justify-around items-center px-2 py-2 shadow-[0_-4px_10px_rgba(0,0,0,0.05)] backdrop-blur-md bg-white/90 dark:bg-gray-900/90 pointer-events-none" id="mmc-bottom-nav">
    <a href="/admin" class="flex flex-col items-center justify-center w-full text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
        <x-heroicon-o-home class="w-6 h-6 mb-1" />
        <span class="text-[10px] font-medium tracking-wide">Início</span>
    </a>
    @can('create', \App\Models\DailyRecord::class)
        <a href="/admin/daily-records/create" class="flex flex-col items-center justify-center w-full text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 relative">
            <div class="absolute -top-6 bg-primary-600 text-white rounded-full p-3 shadow-lg transform hover:scale-105 transition-transform">
                <x-heroicon-o-plus class="w-6 h-6" />
            </div>
            <span class="text-[10px] font-medium tracking-wide mt-6">Registar</span>
        </a>
    @else
        <a href="/admin/daily-records" class="flex flex-col items-center justify-center w-full text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 relative">
            <div class="absolute -top-6 bg-gray-400 text-white rounded-full p-3 shadow-lg">
                <x-heroicon-o-list-bullet class="w-6 h-6" />
            </div>
            <span class="text-[10px] font-medium tracking-wide mt-6">Registos</span>
        </a>
    @endcan
    <a href="/admin/analise-parametros" class="flex flex-col items-center justify-center w-full text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400">
        <x-heroicon-o-chart-bar class="w-6 h-6 mb-1" />
        <span class="text-[10px] font-medium tracking-wide">Análise</span>
    </a>
</div>

<style>
    /* Prevent content from hiding behind bottom nav */
    @media (max-width: 767px) {
        body {
            padding-bottom: 5rem !important;
        }

        /* Bottom nav: sem pointer-events na zona vazia, ativa nos links */
        #mmc-bottom-nav {
            pointer-events: none;
            z-index: 10;
        }

        #mmc-bottom-nav > * {
            pointer-events: auto;
        }

        /* Esconder completamente quando há um modal Filament aberto (editor de
           imagem, confirmação, etc.) — usa :has() nativo, sem JS/MutationObserver
           (que já causou instabilidade e cliques perdidos noutras tentativas). */
        body:has(.fi-modal) #mmc-bottom-nav {
            display: none;
        }

        /* Oculta o botão de menu do sidebar nativo do Filament, se desejado */
        .fi-topbar .fi-sidebar-open-btn {
            /* display: none !important; */
        }
    }
</style>
