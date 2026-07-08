<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Evolução dos Parâmetros</x-slot>

        {{-- Seletores: piscina + eixo esquerdo + eixo direito --}}
        <div class="mb-4">
            {{ $this->form }}
        </div>

        {{-- Tabs + botões de período --}}
        <div class="flex items-center justify-between mb-3 gap-2 flex-wrap">
            <div class="flex gap-0 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden text-sm font-medium">
                <button
                    wire:click="setTab('graph')"
                    @class([
                        'px-4 py-1.5 transition-colors',
                        'bg-primary-600 text-white' => $this->tabAtiva === 'graph',
                        'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $this->tabAtiva !== 'graph',
                    ])
                >Gráfico</button>
                <button
                    wire:click="setTab('table')"
                    @class([
                        'px-4 py-1.5 transition-colors border-l border-gray-200 dark:border-gray-700',
                        'bg-primary-600 text-white' => $this->tabAtiva === 'table',
                        'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $this->tabAtiva !== 'table',
                    ])
                >Tabela</button>
            </div>

            <div class="flex gap-1">
                @foreach(['6h' => '6h', '24h' => '24h', '7d' => '7d', '14d' => '14d', 'custom' => 'Personalizado'] as $key => $label)
                    <button
                        wire:click="setPeriod('{{ $key }}')"
                        @class([
                            'px-3 py-1.5 text-xs font-medium rounded-md transition-colors',
                            'bg-primary-600 text-white shadow-sm' => $this->period === $key,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 border border-gray-200 dark:border-gray-700' => $this->period !== $key,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
        </div>

        @if ($this->tabAtiva === 'graph')
            @php($payload = $this->getChartPayload())

            <div
                x-data="mmcChart({{ Illuminate\Support\Js::from($payload ?: null) }})"
                wire:ignore
            >
                <div x-show="!_hasData" class="mmc-grafico-vazio" x-cloak>Seleciona uma piscina.</div>
                <div x-show="_hasData && !_hasSeries" class="mmc-grafico-vazio" x-cloak>Sem registos neste período.</div>
                <div x-show="_hasSeries" x-cloak>
                    <div class="mmc-grafico-canvas-wrap">
                        <canvas x-ref="canvas"></canvas>
                    </div>
                    <div class="flex items-center justify-between mt-2.5 gap-2 flex-wrap">
                        <span class="text-xs text-gray-400 dark:text-gray-500 hidden sm:block">
                            Ctrl+Scroll para zoom &middot; Arrastar para mover
                        </span>
                        
                        <div class="flex items-center gap-4 ml-auto text-xs">
                            <button
                                type="button"
                                x-on:click="showPrefs = !showPrefs"
                                class="flex items-center gap-1.5 text-gray-600 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 font-medium transition-colors"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                <span>Personalizar Gráfico</span>
                            </button>

                            <button
                                type="button"
                                x-on:click="resetZoom()"
                                class="text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 underline"
                            >Repor zoom</button>
                        </div>
                    </div>

                    {{-- Painel de Personalização Instantânea --}}
                    <div
                        x-show="showPrefs"
                        x-transition
                        x-cloak
                        class="mt-3 p-3.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 text-xs shadow-sm"
                    >
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Tipo de Linha</label>
                                <select
                                    x-model="prefs.curve"
                                    @change="updatePref('curve', prefs.curve)"
                                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-primary-500"
                                >
                                    <option value="smooth">Suave (Curvo)</option>
                                    <option value="linear">Reta (Linear)</option>
                                    <option value="stepped">Degraus (Escada)</option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Preenchimento (Área)</label>
                                <select
                                    x-model="prefs.areaFill"
                                    @change="updatePref('areaFill', prefs.areaFill === 'true' || prefs.areaFill === true)"
                                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-primary-500"
                                >
                                    <option :value="true">Ativado (Gradiente)</option>
                                    <option :value="false">Desativado (Só Linha)</option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Pontos / Marcadores</label>
                                <select
                                    x-model="prefs.showPoints"
                                    @change="updatePref('showPoints', prefs.showPoints)"
                                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-primary-500"
                                >
                                    <option value="auto">Automático</option>
                                    <option value="always">Sempre visíveis</option>
                                    <option value="none">Ocultos</option>
                                </select>
                            </div>

                            <div>
                                <label class="block font-medium text-gray-700 dark:text-gray-300 mb-1">Espessura da Linha</label>
                                <select
                                    x-model="prefs.lineWidth"
                                    @change="updatePref('lineWidth', prefs.lineWidth)"
                                    class="w-full text-xs rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 focus:ring-primary-500"
                                >
                                    <option value="1.5">Fina (1.5px)</option>
                                    <option value="2.5">Normal (2.5px)</option>
                                    <option value="3.5">Grossa (3.5px)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            @php($tableData = $this->getTableRows())

            <div class="space-y-5">
                {{-- Registos Manuais --}}
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Registos Manuais</h3>
                    @if (empty($tableData['manual']))
                        <p class="text-sm text-gray-400 italic">Sem registos no período selecionado.</p>
                    @else
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
                    @endif
                </div>

                {{-- Leituras de Sonda --}}
                <div>
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Leituras de Sonda</h3>
                    @if (empty($tableData['sensor']))
                        <p class="text-sm text-gray-400 italic">Sem leituras de sonda no período selecionado.</p>
                    @else
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
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>

    <style>
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
            font-size: 0.82rem;
        }
        .mmc-tabela th {
            text-align: left;
            padding: 0.4rem 0.75rem;
            font-weight: 600;
            color: var(--fi-color-gray-500, #6b7280);
            border-bottom: 1px solid var(--fi-color-gray-200, #e5e7eb);
            white-space: nowrap;
        }
        .mmc-tabela td {
            padding: 0.4rem 0.75rem;
            border-bottom: 1px solid var(--fi-color-gray-100, #f3f4f6);
            color: var(--fi-color-gray-700, #374151);
        }
        .mmc-tabela tbody tr:hover td {
            background: var(--fi-color-gray-50, #f9fafb);
        }
        .dark .mmc-tabela th {
            color: rgba(255,255,255,0.5);
            border-bottom-color: rgba(255,255,255,0.08);
        }
        .dark .mmc-tabela td {
            color: rgba(255,255,255,0.8);
            border-bottom-color: rgba(255,255,255,0.05);
        }
        .dark .mmc-tabela tbody tr:hover td {
            background: rgba(255,255,255,0.03);
        }

        @media (max-width: 640px) {
            .mmc-grafico-canvas-wrap { height: 260px; }
        }
    </style>
</x-filament-widgets::widget>
