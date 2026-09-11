<x-filament-panels::page>
    @php
        $auditoria = $this->auditoria;
        $instalacoes = $this->instalacoes;
        $piscinas = $this->piscinas;
    @endphp

    <div class="space-y-6">
        <!-- Hero Header: Auditoria Sanitária DGS -->
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-sm">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div class="flex items-center gap-3.5">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center {{ ($auditoria['taxa_conformidade'] ?? 0) >= 95 ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400' : 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400' }}">
                        <x-filament::icon icon="heroicon-o-shield-check" class="w-7 h-7" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-xl font-bold text-slate-900 dark:text-white tracking-tight">Inspeção Sanitária Oficial</h2>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider {{ ($auditoria['taxa_conformidade'] ?? 0) >= 95 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-300' }}">
                                CN 14/DA DGS
                            </span>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            Modo auditoria para exibição imediata à autoridade de saúde pública. Registos oficiais auditáveis.
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2.5">
                    <a href="{{ \App\Filament\Pages\RelatorioPdf::getUrl(['data_inicio' => $this->data_inicio, 'data_fim' => $this->data_fim, 'installation_id' => $this->installation_id]) }}"
                       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-primary-600 hover:bg-primary-700 text-white font-semibold text-sm shadow-sm transition-colors min-h-[44px]">
                        <x-filament::icon icon="heroicon-o-arrow-down-tray" class="w-4 h-4" />
                        <span>Descarregar Livro Oficial (PDF)</span>
                    </a>
                </div>
            </div>

            <!-- Controlo de Seleção & Períodos Táteis -->
            <div class="mt-6 pt-5 border-t border-slate-100 dark:border-slate-800 grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-4">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Instalação</label>
                    <select wire:model.live="installation_id" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm font-medium py-2.5 px-3 min-h-[44px]">
                        @foreach ($instalacoes as $inst)
                            <option value="{{ $inst->id }}">{{ $inst->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-3">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Piscina</label>
                    <select wire:model.live="pool_id" class="w-full rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-sm font-medium py-2.5 px-3 min-h-[44px]">
                        <option value="todas">Todas as piscinas</option>
                        @foreach ($piscinas as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Chips Táteis de Período -->
                <div class="md:col-span-5">
                    <label class="block text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Período de Inspeção</label>
                    <div class="flex items-center gap-1.5 p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200/60 dark:border-slate-700/60">
                        <button type="button" wire:click="setPeriodo('30d')"
                                class="flex-1 py-2 px-2.5 rounded-lg text-xs font-semibold transition-all min-h-[36px] {{ $periodo === '30d' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900' }}">
                            30 Dias
                        </button>
                        <button type="button" wire:click="setPeriodo('este_mes')"
                                class="flex-1 py-2 px-2.5 rounded-lg text-xs font-semibold transition-all min-h-[36px] {{ $periodo === 'este_mes' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900' }}">
                            Este Mês
                        </button>
                        <button type="button" wire:click="setPeriodo('mes_anterior')"
                                class="flex-1 py-2 px-2.5 rounded-lg text-xs font-semibold transition-all min-h-[36px] {{ $periodo === 'mes_anterior' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900' }}">
                            Mês Anterior
                        </button>
                        <button type="button" wire:click="setPeriodo('7d')"
                                class="flex-1 py-2 px-2.5 rounded-lg text-xs font-semibold transition-all min-h-[36px] {{ $periodo === '7d' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900' }}">
                            7 Dias
                        </button>
                    </div>
                </div>
            </div>
        </div>

        @if (! empty($auditoria['valido']))
            <!-- KPI Strip de Conformidade -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="rounded-2xl border p-4 {{ $auditoria['taxa_conformidade'] >= 95 ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/40 dark:bg-emerald-950/20' : 'border-amber-200 bg-amber-50/70 dark:border-amber-900/40 dark:bg-amber-950/20' }}">
                    <span class="text-xs font-semibold uppercase tracking-wider {{ $auditoria['taxa_conformidade'] >= 95 ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400' }}">
                        Conformidade CN 14/DA
                    </span>
                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="text-3xl font-bold {{ $auditoria['taxa_conformidade'] >= 95 ? 'text-emerald-900 dark:text-emerald-100' : 'text-amber-900 dark:text-amber-100' }}">
                            {{ $auditoria['taxa_conformidade'] }}%
                        </span>
                    </div>
                    <p class="text-xs mt-1 text-slate-500 dark:text-slate-400">
                        {{ $auditoria['total_violacoes'] === 0 ? 'Zero violações legais' : $auditoria['total_violacoes'].' leituras fora dos limites' }}
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Análises Realizadas
                    </span>
                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="text-3xl font-bold text-slate-900 dark:text-white">
                            {{ $auditoria['total_registos'] }}
                        </span>
                    </div>
                    <p class="text-xs mt-1 text-slate-500 dark:text-slate-400">
                        Período: {{ $auditoria['periodo_label'] }}
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Lavagens de Filtro
                    </span>
                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="text-3xl font-bold text-slate-900 dark:text-white">
                            {{ $auditoria['total_lavagens'] }}
                        </span>
                    </div>
                    <p class="text-xs mt-1 text-slate-500 dark:text-slate-400">
                        Registadas no período
                    </p>
                </div>

                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Encerramentos Época
                    </span>
                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="text-3xl font-bold text-slate-900 dark:text-white">
                            {{ $auditoria['encerramentos_count'] }}
                        </span>
                    </div>
                    <p class="text-xs mt-1 text-slate-500 dark:text-slate-400">
                        {{ $auditoria['encerramentos_count'] > 0 ? 'Com justificação legal arquivada' : 'Piscinas abertas em operação' }}
                    </p>
                </div>
            </div>

            <!-- Tabela Cronológica Oficial (Audit Trail) -->
            <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 shadow-sm overflow-hidden">
                <div class="p-4 border-b border-slate-100 dark:border-slate-800 flex items-center justify-between">
                    <div>
                        <h3 class="font-bold text-base text-slate-900 dark:text-white">Registo Cronológico de Qualidade da Água</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Parâmetros físicos e químicos obrigatórios pela Circular Normativa 14/DA da DGS.</p>
                    </div>
                    <span class="text-xs font-semibold text-slate-400">
                        {{ count($auditoria['registos']) }} registos
                    </span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs border-collapse">
                        <thead>
                            <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-semibold uppercase tracking-wider text-[10px]">
                                <th class="py-3 px-3">Data / Hora</th>
                                <th class="py-3 px-3">Piscina</th>
                                <th class="py-3 px-3">Técnico</th>
                                <th class="py-3 px-3 text-center">pH<br><span class="text-[9px] font-normal text-slate-400">6.9–8.0</span></th>
                                <th class="py-3 px-3 text-center">Cl. Livre<br><span class="text-[9px] font-normal text-slate-400">mg/L</span></th>
                                <th class="py-3 px-3 text-center">Cl. Total<br><span class="text-[9px] font-normal text-slate-400">mg/L</span></th>
                                <th class="py-3 px-3 text-center">Cl. Comb.<br><span class="text-[9px] font-normal text-slate-400">≤ 0.6</span></th>
                                <th class="py-3 px-3 text-center">Temp.<br><span class="text-[9px] font-normal text-slate-400">°C</span></th>
                                <th class="py-3 px-3 text-center">Turbidez<br><span class="text-[9px] font-normal text-slate-400">FNU</span></th>
                                <th class="py-3 px-3 text-center">Renovação</th>
                                <th class="py-3 px-3 text-center">Filtro</th>
                                <th class="py-3 px-3 text-center">Conforme</th>
                                <th class="py-3 px-3">Observações / Ação Corretiva</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @forelse ($auditoria['registos'] as $reg)
                                <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors {{ ! $reg['conforme'] ? 'bg-rose-50/30 dark:bg-rose-950/10' : '' }}">
                                    <td class="py-2.5 px-3 font-semibold whitespace-nowrap text-slate-800 dark:text-slate-200">
                                        {{ $reg['data'] }} <span class="text-slate-400 font-normal">{{ $reg['hora'] }}</span>
                                    </td>
                                    <td class="py-2.5 px-3 font-medium text-slate-700 dark:text-slate-300 whitespace-nowrap">
                                        {{ $reg['piscina_nome'] }}
                                    </td>
                                    <td class="py-2.5 px-3 text-slate-500 whitespace-nowrap">
                                        {{ $reg['tecnico'] }}
                                    </td>
                                    <!-- pH -->
                                    <td class="py-2.5 px-3 text-center font-bold {{ $reg['ph_ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' }}">
                                        {{ $reg['ph'] }}
                                    </td>
                                    <!-- Cloro Livre -->
                                    <td class="py-2.5 px-3 text-center font-bold {{ $reg['cloro_livre_ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' }}">
                                        {{ $reg['cloro_livre'] }}
                                    </td>
                                    <!-- Cloro Total -->
                                    <td class="py-2.5 px-3 text-center text-slate-600 dark:text-slate-300">
                                        {{ $reg['cloro_total'] }}
                                    </td>
                                    <!-- Cloro Combinado -->
                                    <td class="py-2.5 px-3 text-center font-bold {{ $reg['cloro_combinado_ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' }}">
                                        {{ $reg['cloro_combinado'] }}
                                    </td>
                                    <!-- Temperatura -->
                                    <td class="py-2.5 px-3 text-center text-slate-600 dark:text-slate-300 whitespace-nowrap">
                                        {{ $reg['temperatura'] }}
                                    </td>
                                    <!-- Turbidez -->
                                    <td class="py-2.5 px-3 text-center font-medium {{ $reg['turbidez_ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300' }}">
                                        {{ $reg['turbidez'] }}
                                    </td>
                                    <!-- Renovação -->
                                    <td class="py-2.5 px-3 text-center text-slate-600 dark:text-slate-300">
                                        {{ $reg['renovacao_agua'] }}
                                    </td>
                                    <!-- Lavagem Filtro -->
                                    <td class="py-2.5 px-3 text-center text-slate-600 dark:text-slate-300">
                                        {{ $reg['lavagem_filtro'] }}
                                    </td>
                                    <!-- Conforme -->
                                    <td class="py-2.5 px-3 text-center">
                                        @if ($reg['conforme'])
                                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300 text-xs font-bold">✓</span>
                                        @else
                                            <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-rose-100 text-rose-700 dark:bg-rose-900/60 dark:text-rose-300 text-xs font-bold">✗</span>
                                        @endif
                                    </td>
                                    <!-- Observações -->
                                    <td class="py-2.5 px-3 text-slate-500 max-w-xs truncate" title="{{ $reg['observacoes'] ?? '' }}">
                                        {{ $reg['observacoes'] ?? '—' }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="py-8 text-center text-slate-400">
                                        Nenhum registo diário encontrado para a seleção e período indicados.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="py-12 text-center bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 text-slate-500">
                {{ $auditoria['mensagem'] ?? 'Selecione uma instalação válida.' }}
            </div>
        @endif
    </div>
</x-filament-panels::page>
