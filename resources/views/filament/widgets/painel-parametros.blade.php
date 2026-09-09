<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Evolução dos Parâmetros</x-slot>

        {{-- Seletores: piscina + eixo esquerdo + eixo direito --}}
        <div class="mb-4">
            {{ $this->form }}
        </div>

        @unless ($this->isNS())
            {{-- Atalhos táteis de 1 toque: trocar eixos sem abrir dropdowns --}}
            <div class="flex gap-2 mb-4 overflow-x-auto pb-2 scrollbar-hide whitespace-nowrap items-center">
                <span class="text-[10px] font-bold text-slate-400 dark:text-slate-500 uppercase tracking-widest self-center mr-1">Predefinições</span>
                <button
                    type="button"
                    wire:click="presetSensorVsManual('ph', 'cloro_livre')"
                    class="shrink-0 min-h-[44px] px-4 py-2 text-xs font-semibold rounded-xl bg-sky-50 text-sky-700 border border-sky-200 hover:bg-sky-100 dark:bg-sky-950/40 dark:text-sky-300 dark:border-sky-800 transition-all active:scale-95 flex items-center gap-1.5"
                >
                    <span>📊</span>
                    <span>pH + Cloro Livre</span>
                </button>
                <button
                    type="button"
                    wire:click="presetSensorVsManual('controlador_ph', 'ph')"
                    class="shrink-0 min-h-[44px] px-4 py-2 text-xs font-semibold rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 transition-all active:scale-95 flex items-center gap-1.5"
                >
                    <span>🔄</span>
                    <span>pH (Sonda vs Manual)</span>
                </button>
                <button
                    type="button"
                    wire:click="presetSensorVsManual('controlador_orp', 'cloro_livre')"
                    class="shrink-0 min-h-[44px] px-4 py-2 text-xs font-semibold rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 transition-all active:scale-95 flex items-center gap-1.5"
                >
                    <span>⚡</span>
                    <span>Cloro (ORP vs Livre)</span>
                </button>
                <button
                    type="button"
                    wire:click="presetSensorVsManual('controlador_temp', 'temperatura')"
                    class="shrink-0 min-h-[44px] px-4 py-2 text-xs font-semibold rounded-xl bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700 transition-all active:scale-95 flex items-center gap-1.5"
                >
                    <span>🌡️</span>
                    <span>Temperatura (Sonda vs Manual)</span>
                </button>
            </div>
        @endunless

        {{-- Tabs + botões de período --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-4">
            <div class="inline-flex bg-slate-100/80 dark:bg-slate-800/80 p-1 rounded-2xl w-full sm:w-auto shadow-inner">
                <button
                    type="button"
                    wire:click="setTab('graph')"
                    @class([
                        'flex-1 sm:flex-none min-h-[44px] px-6 py-2 text-sm font-semibold rounded-xl transition-all flex items-center justify-center',
                        'bg-white text-slate-800 dark:bg-slate-600 dark:text-white shadow-sm' => $this->tabAtiva === 'graph',
                        'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $this->tabAtiva !== 'graph',
                    ])
                >Gráfico</button>
                <button
                    type="button"
                    wire:click="setTab('table')"
                    @class([
                        'flex-1 sm:flex-none min-h-[44px] px-6 py-2 text-sm font-semibold rounded-xl transition-all flex items-center justify-center',
                        'bg-white text-slate-800 dark:bg-slate-600 dark:text-white shadow-sm' => $this->tabAtiva === 'table',
                        'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200' => $this->tabAtiva !== 'table',
                    ])
                >Tabela</button>
            </div>

            <div class="flex gap-1 overflow-x-auto pb-1 scrollbar-hide">
                @if ($this->isNS())
                    <span class="min-h-[44px] px-4 py-2 text-xs font-bold rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-900/30 dark:text-primary-400 flex items-center">Últimas 12h</span>
                @else
                    @foreach(['6h' => '6h', '24h' => '24h', '7d' => '7d', '14d' => '14d', '30d' => '30d', 'custom' => 'Personalizado'] as $key => $label)
                        <button
                            type="button"
                            wire:click="setPeriod('{{ $key }}')"
                            @class([
                                'shrink-0 min-h-[44px] px-4 py-2 text-xs font-semibold rounded-xl transition-all active:scale-95 flex items-center justify-center',
                                'bg-slate-800 text-white dark:bg-slate-200 dark:text-slate-900 shadow-md' => $this->period === $key,
                                'bg-slate-50 text-slate-500 hover:bg-slate-100 dark:bg-slate-800/50 dark:text-slate-400 border border-slate-200 dark:border-slate-700' => $this->period !== $key,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                @endif
            </div>
        </div>

        @if ($this->tabAtiva === 'graph')
            @php($payload = $this->getChartPayload())

            <div
                x-data="mmcChart({{ Illuminate\Support\Js::from($payload ?: null) }})"
                wire:key="mmc-painel-grafico"
                wire:ignore
            >
                <div x-show="!_hasData" class="mmc-grafico-vazio" x-cloak>Seleciona uma piscina.</div>
                <div x-show="_hasData && !_hasSeries" class="mmc-grafico-vazio" x-cloak>Sem registos neste período.</div>
                <div x-show="_hasSeries" x-cloak>
                    <div class="mmc-grafico-canvas-wrap">
                        <canvas x-ref="canvas"></canvas>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <span class="text-xs text-gray-400 dark:text-gray-500 hidden sm:block">
                            Ctrl+Scroll para zoom &middot; Arrastar para mover
                        </span>
                        <button
                            type="button"
                            x-on:click="resetZoom()"
                            class="text-xs text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 underline ml-auto"
                        >Repor zoom</button>
                    </div>
                </div>
            </div>
        @else
            @php($tableData = $this->getTableRows())
            <div wire:key="mmc-painel-tabela">

            <div class="space-y-5">
                {{-- Registos Manuais --}}
                <div>
                    <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200 mb-3 ml-1 tracking-tight">Registos Manuais</h3>
                    @if (empty($tableData['manual']))
                        <p class="text-sm text-slate-400 italic ml-1">Sem registos no período selecionado.</p>
                    @else
                        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-sm">
                            <div class="overflow-x-auto">
                                <table class="mmc-tabela">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>pH</th>
                                        <th>Cl. Livre (mg/L)</th>
                                        <th>Cl. Total (mg/L)</th>
                                        <th>Turbidez (FNU)</th>
                                        <th>Temp. (°C)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($tableData['manual'] as $row)
                                        <tr>
                                            <td class="tabular-nums">{{ $row['data'] }}</td>
                                            <td class="tabular-nums">{{ $row['ph'] }}</td>
                                            <td class="tabular-nums">{{ $row['cloro_livre'] }}</td>
                                            <td class="tabular-nums">{{ $row['cloro_total'] }}</td>
                                            <td class="tabular-nums">{{ $row['turbidez'] }}</td>
                                            <td class="tabular-nums">{{ $row['temperatura'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        </div>
                    @endif
                </div>

                {{-- Leituras de Sonda --}}
                <div>
                    <h3 class="text-sm font-bold text-slate-800 dark:text-slate-200 mb-3 ml-1 tracking-tight mt-6">Leituras de Sonda (Hanna)</h3>
                    @if (empty($tableData['sensor']))
                        <p class="text-sm text-slate-400 italic ml-1">Sem leituras de sonda no período selecionado.</p>
                    @else
                        <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl overflow-hidden shadow-sm">
                            <div class="overflow-x-auto">
                                <table class="mmc-tabela">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>pH</th>
                                        <th>ORP (mV)</th>
                                        <th>Temp. Água (°C)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($tableData['sensor'] as $row)
                                        <tr>
                                            <td class="tabular-nums">{{ $row['data'] }}</td>
                                            <td class="tabular-nums">{{ $row['ph'] }}</td>
                                            <td class="tabular-nums">{{ $row['orp'] }}</td>
                                            <td class="tabular-nums">{{ $row['temp'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        </div>
                    @endif
                </div>
            </div>
            </div>
        @endif
    </x-filament::section>

    <style>
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .mmc-grafico-canvas-wrap {
            position: relative;
            height: 380px;
        }
        .mmc-grafico-vazio {
            opacity: 0.55;
            font-size: 0.9rem;
            padding: 2rem 0;
            text-align: center;
        }
        .mmc-tabela {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .mmc-tabela th {
            text-align: left;
            padding: 0.75rem 1rem;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b; /* slate-500 */
            border-bottom: 1px solid #e2e8f0; /* slate-200 */
            white-space: nowrap;
        }
        .mmc-tabela td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9; /* slate-100 */
            color: #334155; /* slate-700 */
            font-weight: 500;
        }
        .mmc-tabela tbody tr {
            transition: background-color 0.2s ease;
        }
        .mmc-tabela tbody tr:hover td {
            background-color: #f8fafc; /* slate-50 */
        }
        .dark .mmc-tabela th {
            color: #94a3b8; /* slate-400 */
            border-bottom-color: rgba(255,255,255,0.1);
        }
        .dark .mmc-tabela td {
            color: #e2e8f0; /* slate-200 */
            border-bottom-color: rgba(255,255,255,0.05);
        }
        .dark .mmc-tabela tbody tr:hover td {
            background-color: rgba(255,255,255,0.03);
        }

        @media (max-width: 640px) {
            .mmc-grafico-canvas-wrap { height: 260px; }
        }
    </style>
</x-filament-widgets::widget>
