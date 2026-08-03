@php
    $resumo = $this->getResumo();
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-3 -mt-2 text-sm">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-success-50 dark:bg-success-950 px-3 py-1 font-medium text-success-700 dark:text-success-400">
            <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4" />
            {{ $resumo['abertas'] }} {{ $resumo['abertas'] === 1 ? 'piscina aberta' : 'piscinas abertas' }}
        </span>

        @if ($resumo['encerradas'] > 0)
            <span class="inline-flex items-center gap-1.5 rounded-full bg-warning-50 dark:bg-warning-950 px-3 py-1 font-medium text-warning-700 dark:text-warning-400">
                <x-filament::icon icon="heroicon-m-lock-closed" class="h-4 w-4" />
                {{ $resumo['encerradas'] }} {{ $resumo['encerradas'] === 1 ? 'encerrada' : 'encerradas' }}
            </span>
        @endif

        @if ($resumo['proxima_reabertura'])
            <span class="text-gray-500 dark:text-gray-400">
                Próxima reabertura prevista: {{ $resumo['proxima_reabertura']->format('d/m/Y') }}
            </span>
        @endif
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Encerrar uma piscina não apaga nada: os registos do período mantêm-se e o livro sanitário passa a
        imprimir a justificação dos dias sem registos. Enquanto estiver encerrada, deixa de gerar alertas de
        falta de registo e sai das médias de conformidade.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
