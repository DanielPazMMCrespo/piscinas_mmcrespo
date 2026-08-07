<div class="flex-1 flex flex-col h-full px-4 pt-6" x-data="{ isSharing: false }">
     
    <header class="flex items-center justify-between mb-8 shrink-0">
        <h1 class="text-2xl font-bold tracking-tight text-white">Exportar</h1>
    </header>

    <div class="flex-1 flex flex-col justify-center max-w-sm mx-auto w-full space-y-8 pb-12">
        
        <!-- Ícone ilustrativo central gigante -->
        <div class="flex justify-center mb-4">
            <div class="w-32 h-32 rounded-full bg-slate-800/50 flex items-center justify-center border border-slate-700/50 shadow-inner">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-16 h-16 text-slate-400">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                </svg>
            </div>
        </div>

        <div class="flex flex-col space-y-2">
            <label class="text-sm font-semibold text-slate-400 text-center">Mês do Relatório</label>
            <!-- Usamos input type=month nativo, gigante e centrado -->
            <input type="month" wire:model="period" 
                   class="w-full h-20 text-center text-3xl font-bold bg-slate-900 border border-slate-700/50 rounded-3xl text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition-shadow appearance-none" />
        </div>

        <!-- Botão Massivo -->
        <button type="button" 
                x-on:click="async () => {
                    if (!$wire.period) return;
                    isSharing = true;
                    try {
                        const res = await fetch('/api/pdf/export?period=' + $wire.period);
                        const blob = await res.blob();
                        const filename = 'relatorio_' + $wire.period + '.pdf';
                        const file = new File([blob], filename, { type: 'application/pdf' });
                        
                        if (navigator.share && navigator.canShare({ files: [file] })) {
                            await navigator.share({
                                title: 'Relatório Piscinas - ' + $wire.period,
                                text: 'Aqui está o relatório das piscinas.',
                                files: [file]
                            });
                        } else {
                            alert('Partilha nativa não suportada. A iniciar download...');
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = filename;
                            a.click();
                            URL.revokeObjectURL(url);
                        }
                    } catch(e) {
                        console.error('Erro ao partilhar:', e);
                    } finally {
                        isSharing = false;
                    }
                }"
                x-bind:disabled="isSharing"
                class="w-full h-20 rounded-3xl bg-indigo-600 text-white font-bold text-xl tracking-wide shadow-lg shadow-indigo-600/30 active:scale-95 active:bg-indigo-700 transition-all flex items-center justify-center disabled:opacity-50 disabled:scale-100 mt-4">
            
            <div x-show="!isSharing" class="flex items-center">
                <!-- Share Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6 mr-3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7.217 10.907a2.25 2.25 0 1 0 0 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186 9.566-5.314m-9.566 7.5 9.566 5.314m0 0a2.25 2.25 0 1 0 3.935 2.186 2.25 2.25 0 0 0-3.935-2.186Zm0-12.814a2.25 2.25 0 1 0 3.933-2.185 2.25 2.25 0 0 0-3.933 2.185Z" />
                </svg>
                PARTILHAR RELATÓRIO
            </div>
            
            <div x-show="isSharing" class="flex items-center" style="display: none;">
                <svg class="animate-spin -ml-1 mr-3 h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                A Gerar...
            </div>
        </button>

    </div>
</div>
