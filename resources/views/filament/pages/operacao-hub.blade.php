<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto py-6">
        <!-- Card Registo Diário -->
        <button type="button" 
                wire:click="mountAction('registoDiario')"
                class="flex flex-col items-center justify-center p-8 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm hover:shadow-md hover:border-primary-500 dark:hover:border-primary-500 transition-all group text-center cursor-pointer">
            <div class="p-4 rounded-full bg-primary-50 dark:bg-primary-950/30 text-primary-600 dark:text-primary-400 mb-6 group-hover:scale-105 transition-transform">
                <x-heroicon-o-clipboard-document-check class="w-12 h-12" />
            </div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors">
                Registo Diário
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
                Registe os parâmetros da água ou consulte o histórico e conformidades das piscinas.
            </p>
        </button>

        <!-- Card Incidentes -->
        <a href="{{ \App\Filament\Resources\IncidentResource::getUrl('index') }}"
           class="flex flex-col items-center justify-center p-8 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm hover:shadow-md hover:border-danger-500 dark:hover:border-danger-500 transition-all group text-center cursor-pointer">
            <div class="p-4 rounded-full bg-danger-50 dark:bg-danger-950/30 text-danger-600 dark:text-danger-400 mb-6 group-hover:scale-105 transition-transform">
                <x-heroicon-o-exclamation-triangle class="w-12 h-12" />
            </div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2 group-hover:text-danger-600 dark:group-hover:text-danger-400 transition-colors">
                Incidentes
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-xs">
                Registe e faça a gestão de avarias, fugas de água ou outros problemas operacionais.
            </p>
        </a>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
