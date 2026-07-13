<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Evolução dos Parâmetros</x-slot>
        <x-slot name="headerEnd">
            {{ $this->configurarGraficosAction }}
        </x-slot>

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
                x-data="mmcEcharts({{ Illuminate\Support\Js::from($payload ?: null) }})"
                wire:ignore
            >
                <div x-show="!_hasData" class="mmc-grafico-vazio" x-cloak>Seleciona uma piscina ou configura gráficos visíveis.</div>
                <div x-show="_hasData && !_hasSeries" class="mmc-grafico-vazio" x-cloak>Sem registos neste período.</div>
                <div x-show="_hasSeries" x-cloak>
                    <div class="mmc-grafico-canvas-wrap">
                        <div x-ref="container" :style="`width: 100%; height: ${Math.max(400, (_payload?.graphs?.length || 1) * 320 + 60)}px;`"></div>
                    </div>
                    <div class="flex items-center justify-between mt-2">
                        <span class="text-xs text-gray-400 dark:text-gray-500 hidden sm:block">
                            Dica: A barra inferior permite navegar em todos os gráficos em simultâneo.
                        </span>
                        <button
                            x-on:click="resetZoom()"
                            class="text-xs text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 underline ml-auto"
                        >Repor zoom</button>
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
            min-height: 400px;
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

    </style>
    <x-filament-actions::modals />
</x-filament-widgets::widget>
