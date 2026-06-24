<div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Confirme que os valores introduzidos estão corretos. Depois de submetido, só o administrador pode alterar este registo.
    </p>

    @if(count($problemas) > 0)
        <div class="mt-4 rounded-lg bg-danger-50 dark:bg-danger-900/30 p-4 border border-danger-200 dark:border-danger-800">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <x-heroicon-s-x-circle class="w-5 h-5 text-danger-500" />
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-danger-800 dark:text-danger-200">
                        Atenção! Parâmetros fora da norma legal:
                    </h3>
                    <div class="mt-2 text-sm text-danger-700 dark:text-danger-300">
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach($problemas as $problema)
                                <li>{{ $problema }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <div class="mt-3 text-xs font-semibold text-danger-800 dark:text-danger-200">
                        O Administrador será notificado caso decida gravar o registo assim.
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="mt-4 rounded-lg bg-success-50 dark:bg-success-900/30 p-4 border border-success-200 dark:border-success-800">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <x-heroicon-s-check-circle class="w-5 h-5 text-success-500" />
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-success-800 dark:text-success-200">
                        Todos os parâmetros químicos estão conformes a legislação.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
