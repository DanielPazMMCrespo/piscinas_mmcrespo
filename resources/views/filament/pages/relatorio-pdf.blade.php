<x-filament-panels::page>
    {{-- Formulário de parâmetros + exportação. O download é devolvido pela action Livewire "exportar". --}}
    <form wire:submit="exportar" class="space-y-6">
        {{ $this->form }}

        @php
            $summary = $this->preflightSummary;
        @endphp

        @if ($summary['valido'])
            <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-gray-100 pb-4 dark:border-gray-800">
                    <div class="flex items-center gap-3">
                        @if ($summary['totalViolacoes'] === 0)
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 ring-1 ring-emerald-500/20 dark:bg-emerald-950/40 dark:text-emerald-400">
                                <x-heroicon-s-shield-check class="h-6 w-6" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pré-Validação Sanitária (DGS CN 14/DA)</h3>
                                <p class="text-xs text-emerald-600 dark:text-emerald-400 font-medium">100% Conforme com a regulamentação legal</p>
                            </div>
                        @else
                            <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600 ring-1 ring-amber-500/20 dark:bg-amber-950/40 dark:text-amber-400">
                                <x-heroicon-s-exclamation-triangle class="h-6 w-6" />
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Pré-Validação Sanitária (DGS CN 14/DA)</h3>
                                <p class="text-xs text-amber-600 dark:text-amber-400 font-medium">{{ $summary['totalViolacoes'] }} {{ $summary['totalViolacoes'] === 1 ? 'desvio detetado' : 'desvios detetados' }} (ações corretivas obrigatórias)</p>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($summary['termoElegivel'])
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400">
                                <x-heroicon-m-check-badge class="h-4 w-4" />
                                Termo Legal Elegível (Anexo III)
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/20 dark:bg-gray-800 dark:text-gray-400">
                                <x-heroicon-m-information-circle class="h-4 w-4" />
                                Relatório Geral / Multi-Piscina
                            </span>
                        @endif
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 p-3.5 dark:bg-gray-800/60">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Registos no Período</span>
                        <div class="mt-1 flex items-baseline gap-1.5">
                            <span class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $summary['totalRegistos'] }}</span>
                            <span class="text-xs text-gray-500">em {{ $summary['diasPeriodo'] }} {{ $summary['diasPeriodo'] === 1 ? 'dia' : 'dias' }}</span>
                        </div>
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3.5 dark:bg-gray-800/60">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Taxa Conformidade</span>
                        <div class="mt-1 flex items-baseline gap-1.5">
                            <span class="text-xl font-bold tracking-tight {{ $summary['taxaConformidade'] >= 95 ? 'text-emerald-600 dark:text-emerald-400' : ($summary['taxaConformidade'] >= 80 ? 'text-amber-600 dark:text-amber-400' : 'text-rose-600 dark:text-rose-400') }}">
                                {{ $summary['taxaConformidade'] }}%
                            </span>
                        </div>
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3.5 dark:bg-gray-800/60">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Lavagens de Filtro</span>
                        <div class="mt-1 flex items-baseline gap-1.5">
                            <span class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $summary['totalLavagens'] }}</span>
                            <span class="text-xs text-gray-500">retrolavagens</span>
                        </div>
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3.5 dark:bg-gray-800/60">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Encerramentos Legais</span>
                        <div class="mt-1 flex items-baseline gap-1.5">
                            <span class="text-xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $summary['encerramentosCount'] }}</span>
                            <span class="text-xs text-gray-500">{{ $summary['encerramentosCount'] === 1 ? 'período' : 'períodos' }}</span>
                        </div>
                    </div>
                </div>

                @if ($summary['totalViolacoes'] > 0)
                    <div class="mt-3 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-300">
                        <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 mt-0.5 text-amber-600 dark:text-amber-400" />
                        <span>
                            <strong>Atenção Inspetiva:</strong> Foram detetados {{ $summary['totalViolacoes'] }} registos fora das normas CN 14/DA. Certifique-se de que cada desvio inclui a correspondente <em>Ação Corretiva</em> antes da entrega às autoridades de saúde.
                        </span>
                    </div>
                @endif
            </div>
        @endif

        <div class="flex flex-col sm:flex-row gap-3 pt-2">
            <x-filament::button
                type="submit"
                size="lg"
                icon="heroicon-o-arrow-down-tray"
                wire:loading.attr="disabled"
                class="min-h-[44px] text-base font-semibold shadow-sm"
            >
                <span wire:loading.remove wire:target="exportar">Exportar PDF Oficial</span>
                <span wire:loading wire:target="exportar">A processar PDF…</span>
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                size="lg"
                icon="heroicon-o-table-cells"
                wire:click="exportarCsv"
                wire:loading.attr="disabled"
                class="min-h-[44px]"
            >
                <span wire:loading.remove wire:target="exportarCsv">Exportar CSV</span>
                <span wire:loading wire:target="exportarCsv">A preparar CSV…</span>
            </x-filament::button>
        </div>
    </form>

    <x-filament::section icon="heroicon-o-information-circle" collapsible collapsed>
        <x-slot name="heading">Sobre este relatório</x-slot>

        <div class="text-sm space-y-2">
            <p>
                O documento gerado corresponde ao <strong>Livro de Registo Sanitário</strong> exigido pela
                Circular Normativa 14/DA (DGS, 2009), com validação automática de conformidade de pH,
                cloro livre, cloro combinado e temperatura contra os limites legais e os limites próprios
                de cada piscina.
            </p>
            <p>
                Registos corrigidos pelos técnicos são excluídos (mantém-se apenas a versão válida,
                assinalada com "(correção)"), em linha com o modelo append-only da aplicação.
            </p>
            <p>
                Na tabela de leituras automáticas da sonda, o valor na coluna de <strong>Cloro Livre Manual</strong> apenas é apresentado quando um registo manual e uma leitura automática coincidem, permitindo verificar a correspondência entre o cloro livre e o valor de ORP medido. Caso o valor de cloro livre manual não seja credível (por estar fora dos limites legais ou em incoerência com o ORP), o mesmo é apresentado com destaque numa cor específica e acompanhado pela indicação do motivo.
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
