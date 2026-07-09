<div class="flex flex-col gap-4 py-2">
    <!-- Criar Registo -->
    <a href="{{ \App\Filament\Resources\DailyRecordResource::getUrl('create') }}" 
       class="flex items-center justify-between p-4 rounded-xl border border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors group">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-primary-50 dark:bg-primary-950/30 text-primary-600 dark:text-primary-400">
                <x-heroicon-o-plus class="w-6 h-6" />
            </div>
            <div class="text-left">
                <span class="block font-semibold text-gray-900 dark:text-white">Criar registo</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">Inserir novo registo diário de água</span>
            </div>
        </div>
        <x-heroicon-m-chevron-right class="w-5 h-5 text-gray-400 group-hover:text-primary-600 dark:group-hover:text-primary-400 transition-colors" />
    </a>

    <!-- Ver Registos -->
    <a href="{{ \App\Filament\Resources\DailyRecordResource::getUrl('index') }}" 
       class="flex items-center justify-between p-4 rounded-xl border border-gray-200 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-900 transition-colors group">
        <div class="flex items-center gap-3">
            <div class="p-2 rounded-lg bg-success-50 dark:bg-success-950/30 text-success-600 dark:text-success-400">
                <x-heroicon-o-list-bullet class="w-6 h-6" />
            </div>
            <div class="text-left">
                <span class="block font-semibold text-gray-900 dark:text-white">Ver registos</span>
                <span class="block text-xs text-gray-500 dark:text-gray-400">Consultar histórico e conformidades</span>
            </div>
        </div>
        <x-heroicon-m-chevron-right class="w-5 h-5 text-gray-400 group-hover:text-success-600 dark:group-hover:text-success-400 transition-colors" />
    </a>
</div>
