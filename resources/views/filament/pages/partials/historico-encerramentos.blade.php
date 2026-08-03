<div class="space-y-3">
    @forelse ($encerramentos as $encerramento)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3 text-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-semibold text-gray-900 dark:text-gray-100">
                    {{ $encerramento->motivo_label }}
                </span>

                @if ($encerramento->esta_vigente)
                    <span class="rounded-full bg-warning-50 dark:bg-warning-950 px-2 py-0.5 text-xs font-medium text-warning-700 dark:text-warning-400">
                        Em vigor
                    </span>
                @endif
            </div>

            <div class="mt-1 text-gray-600 dark:text-gray-400">
                {{ ucfirst($encerramento->descricao_periodo) }}
                ({{ $encerramento->dias }} {{ $encerramento->dias === 1 ? 'dia' : 'dias' }})
                @if ($encerramento->agua_em_tratamento)
                    · água em tratamento
                @endif
            </div>

            @if ($encerramento->observacoes)
                <p class="mt-1 text-gray-600 dark:text-gray-400">{{ $encerramento->observacoes }}</p>
            @endif

            <div class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                Encerrada por {{ $encerramento->encerradaPor?->name ?? 'utilizador removido' }}
                em {{ $encerramento->created_at->format('d/m/Y H:i') }}
                @if ($encerramento->reaberta_em)
                    · reaberta por {{ $encerramento->reabertaPor?->name ?? 'utilizador removido' }}
                    em {{ $encerramento->reaberta_em->format('d/m/Y H:i') }}
                @endif
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">Esta piscina nunca foi encerrada.</p>
    @endforelse
</div>
