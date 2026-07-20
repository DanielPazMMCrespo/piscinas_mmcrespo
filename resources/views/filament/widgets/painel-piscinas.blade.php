<x-filament-widgets::widget>
    @if ($totalPiscinas > 0)
        <!-- Top KPIs -->
        <div class="neo-top-kpis">
            @unless ($isNS)
                <div class="neo-kpi-card">
                    <div>
                        <div class="text-sm font-medium text-slate-500 uppercase tracking-wider mb-1">Registos Hoje</div>
                        <div class="text-3xl font-bold text-slate-800">{{ $registadasHoje }}<span class="text-lg text-slate-400 font-normal">/{{ $totalPiscinas }}</span></div>
                    </div>
                    <!-- Placeholder Donut / Progress -->
                    <div style="width: 50px; height: 50px; position: relative;">
                        <svg viewBox="0 0 36 36" style="width: 100%; height: 100%;">
                            <path class="text-slate-100" stroke-width="4" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                            <path class="text-blue-500" stroke-width="4" stroke-dasharray="{{ $percentagemRegisto }}, 100" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        </svg>
                    </div>
                </div>
            @endunless
            <div class="neo-kpi-card">
                <div>
                    <div class="text-sm font-medium text-slate-500 uppercase tracking-wider mb-1">Piscinas Conformes</div>
                    <div class="text-3xl font-bold text-slate-800">{{ $conformes }}<span class="text-lg text-slate-400 font-normal">/{{ $totalPiscinas }}</span></div>
                </div>
                <div style="width: 50px; height: 50px; position: relative;">
                    <svg viewBox="0 0 36 36" style="width: 100%; height: 100%;">
                        <path class="text-slate-100" stroke-width="4" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        <path class="text-emerald-500" stroke-width="4" stroke-dasharray="{{ $percentagemConforme }}, 100" stroke-linecap="round" stroke="currentColor" fill="none" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    </svg>
                </div>
            </div>
        </div>
    @endif

    <!-- Main Header & Action -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold text-slate-800 dark:text-white uppercase tracking-tight">Painel de Controlo</h2>
            <p class="text-sm text-slate-500 mt-1">Visão global das piscinas.</p>
        </div>
        @can('create', \App\Models\DailyRecord::class)
            <a href="{{ $urlRegistar }}" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium text-sm py-2 px-4 rounded-xl transition-colors shadow-sm">
                <x-filament::icon icon="heroicon-m-document-text" class="w-4 h-4" />
                Relatório Rápido
            </a>
        @endcan
    </div>

    <!-- Pools Grid -->
    <div class="neo-pool-grid">
        @forelse ($piscinas as $item)
            @php
                $piscina = $item['piscina'];
                $conformes = $item['parametros_conformes'];
                $numFora = count(array_filter($conformes, fn ($ok) => $ok === false));
                $temDados = $item['tem_dados_conformes'];
                $estadoGeral = ! $temDados ? 'neutro' : ($numFora > 0 ? 'bad' : 'ok');
            @endphp
            <div class="neo-pool-card" wire:key="pool-card-{{ $piscina->id }}">
                
                <!-- Card Header -->
                <div class="neo-pool-header">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-blue-50 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <x-filament::icon icon="heroicon-o-swatch" class="w-6 h-6" />
                        </div>
                        <div>
                            <h3 class="neo-pool-title">{{ $piscina->name }}</h3>
                            <div class="text-xs text-slate-500 mt-0.5">{{ $piscina->instalacao?->name ?? 'Sem Instalação' }}</div>
                        </div>
                    </div>
                    <button type="button" class="text-slate-400 hover:text-slate-600 p-1">
                        <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="w-6 h-6" />
                    </button>
                </div>

                <!-- Metrics Grid 2x2 -->
                <div class="neo-metrics-grid">
                    @if ($item['controlador'])
                        <!-- pH -->
                        <div class="neo-metric-card @if($item['controlador']['ph_ok'] === false) neo-metric-card--alert @endif">
                            <div class="neo-metric-label">
                                <span>pH</span>
                                <x-filament::icon icon="heroicon-o-information-circle" class="w-4 h-4 opacity-50" />
                            </div>
                            <div class="neo-metric-value">{{ $item['controlador']['ph'] ?? '—' }}</div>
                            <!-- Sparkline Placeholder -->
                            <svg class="neo-metric-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                                <path d="M0,20 Q10,25 20,15 T40,10 T60,25 T80,15 T100,20 L100,30 L0,30 Z" fill="{{ $item['controlador']['ph_ok'] === false ? '#ffe4e6' : '#eff6ff' }}" opacity="0.6"/>
                                <path d="M0,20 Q10,25 20,15 T40,10 T60,25 T80,15 T100,20" fill="none" stroke="{{ $item['controlador']['ph_ok'] === false ? '#f43f5e' : '#3b82f6' }}" stroke-width="2"/>
                            </svg>
                            <div class="neo-metric-status @if($item['controlador']['ph_ok'] === false) neo-metric-status--bad @elseif($item['controlador']['ph_ok'] === true) neo-metric-status--ok @endif">
                                <div class="w-2 h-2 rounded-full @if($item['controlador']['ph_ok'] === false) bg-rose-500 @elseif($item['controlador']['ph_ok'] === true) bg-emerald-500 @else bg-slate-300 @endif"></div>
                                {{ $item['controlador']['ph_ok'] === false ? 'Alert' : ($item['controlador']['ph_ok'] === true ? 'OK' : 'N/A') }}
                            </div>
                        </div>

                        <!-- ORP/Cloro -->
                        <div class="neo-metric-card @if($item['controlador']['middle_ok'] === false) neo-metric-card--alert @endif">
                            <div class="neo-metric-label">
                                <span>{{ $item['controlador']['middle_label'] }}</span>
                                <x-filament::icon icon="heroicon-o-information-circle" class="w-4 h-4 opacity-50" />
                            </div>
                            <div class="neo-metric-value">{{ $item['controlador']['middle_value'] ?? '—' }}</div>
                            <!-- Sparkline Placeholder -->
                            <svg class="neo-metric-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                                <path d="M0,15 Q20,10 40,20 T80,10 T100,15 L100,30 L0,30 Z" fill="{{ $item['controlador']['middle_ok'] === false ? '#ffe4e6' : '#eff6ff' }}" opacity="0.6"/>
                                <path d="M0,15 Q20,10 40,20 T80,10 T100,15" fill="none" stroke="{{ $item['controlador']['middle_ok'] === false ? '#f43f5e' : '#3b82f6' }}" stroke-width="2"/>
                            </svg>
                            <div class="neo-metric-status @if($item['controlador']['middle_ok'] === false) neo-metric-status--bad @elseif($item['controlador']['middle_ok'] === true) neo-metric-status--ok @endif">
                                <div class="w-2 h-2 rounded-full @if($item['controlador']['middle_ok'] === false) bg-rose-500 @elseif($item['controlador']['middle_ok'] === true) bg-emerald-500 @else bg-slate-300 @endif"></div>
                                {{ $item['controlador']['middle_ok'] === false ? 'Alert' : ($item['controlador']['middle_ok'] === true ? 'OK' : 'N/A') }}
                            </div>
                        </div>

                        <!-- Temp -->
                        <div class="neo-metric-card @if($item['controlador']['temp_ok'] === false) neo-metric-card--alert @endif">
                            <div class="neo-metric-label">
                                <span>Temp.</span>
                                <x-filament::icon icon="heroicon-o-information-circle" class="w-4 h-4 opacity-50" />
                            </div>
                            <div class="neo-metric-value">{{ $item['controlador']['temp'] ?? '—' }}</div>
                            <!-- Sparkline Placeholder -->
                            <svg class="neo-metric-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                                <path d="M0,25 Q15,25 30,20 T60,10 T80,15 T100,20 L100,30 L0,30 Z" fill="{{ $item['controlador']['temp_ok'] === false ? '#ffe4e6' : '#eff6ff' }}" opacity="0.6"/>
                                <path d="M0,25 Q15,25 30,20 T60,10 T80,15 T100,20" fill="none" stroke="{{ $item['controlador']['temp_ok'] === false ? '#f43f5e' : '#3b82f6' }}" stroke-width="2"/>
                            </svg>
                            <div class="neo-metric-status @if($item['controlador']['temp_ok'] === false) neo-metric-status--bad @elseif($item['controlador']['temp_ok'] === true) neo-metric-status--ok @endif">
                                <div class="w-2 h-2 rounded-full @if($item['controlador']['temp_ok'] === false) bg-rose-500 @elseif($item['controlador']['temp_ok'] === true) bg-emerald-500 @else bg-slate-300 @endif"></div>
                                {{ $item['controlador']['temp_ok'] === false ? 'Alert' : ($item['controlador']['temp_ok'] === true ? 'OK' : 'N/A') }}
                            </div>
                        </div>

                        <!-- Info extra / Fonte -->
                        <div class="neo-metric-card flex justify-center items-center text-center p-3">
                            <div class="text-xs text-slate-500">
                                @if ($item['controlador']['origem'] === 'controlador')
                                    Dados do Controlador<br><span class="font-medium">Atualizado {{ $item['controlador']['atualizado_ha'] }}</span>
                                @elseif ($item['controlador']['origem'] === 'manual')
                                    Registo Manual<br><span class="font-medium">{{ $item['controlador']['atualizado_ha'] }}</span>
                                @elseif ($item['controlador']['origem'] === 'artefacto')
                                    {{ $item['controlador']['artefacto'] }}<br><span class="font-medium">{{ $item['controlador']['atualizado_ha'] }}</span>
                                @else
                                    Controlador Offline<br><span class="font-medium">{{ $item['controlador']['atualizado_ha'] }}</span>
                                @endif
                            </div>
                        </div>
                    @elseif (! empty($item['metricas']))
                        <!-- Fallback to Fotómetro data if no array from 'controlador' key -->
                        @foreach ($item['metricas'] as $idx => $metrica)
                            @if ($idx < 3) <!-- Ensure max 3 metrics to fit grid nicely + 1 info card -->
                            <div class="neo-metric-card @if($metrica['ok'] === false) neo-metric-card--alert @endif">
                                <div class="neo-metric-label">
                                    <span>{{ $metrica['label'] }}</span>
                                    <x-filament::icon icon="heroicon-o-information-circle" class="w-4 h-4 opacity-50" />
                                </div>
                                <div class="neo-metric-value">{{ $metrica['valor'] }}</div>
                                <!-- Sparkline Placeholder -->
                                <svg class="neo-metric-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                                    <path d="M0,20 Q10,25 20,15 T40,10 T60,25 T80,15 T100,20 L100,30 L0,30 Z" fill="{{ $metrica['ok'] === false ? '#ffe4e6' : '#eff6ff' }}" opacity="0.6"/>
                                    <path d="M0,20 Q10,25 20,15 T40,10 T60,25 T80,15 T100,20" fill="none" stroke="{{ $metrica['ok'] === false ? '#f43f5e' : '#3b82f6' }}" stroke-width="2"/>
                                </svg>
                                <div class="neo-metric-status @if($metrica['ok'] === false) neo-metric-status--bad @elseif($metrica['ok'] === true) neo-metric-status--ok @endif">
                                    <div class="w-2 h-2 rounded-full @if($metrica['ok'] === false) bg-rose-500 @elseif($metrica['ok'] === true) bg-emerald-500 @else bg-slate-300 @endif"></div>
                                    {{ $metrica['ok'] === false ? 'Alert' : ($metrica['ok'] === true ? 'OK' : 'N/A') }}
                                </div>
                            </div>
                            @endif
                        @endforeach
                        <div class="neo-metric-card flex justify-center items-center text-center p-3">
                            <div class="text-xs text-slate-500">
                                Fotómetro<br><span class="font-medium">{{ $item['ha_quanto'] }}</span>
                            </div>
                        </div>
                    @else
                        <div class="col-span-full py-8 text-center text-sm text-slate-500">
                            Nenhum dado recente encontrado para esta piscina.
                        </div>
                    @endif
                </div>

                <!-- Actions Footer -->
                @if (\App\Filament\Resources\OperationalActionResource::canCreate())
                    <div class="neo-pool-actions">
                        @foreach ($item['acoes_rapidas'] as $idx => $acao)
                            <a href="{{ $acao['url'] }}" class="neo-action-btn @if($idx === 0) neo-action-btn--primary @else neo-action-btn--outline @endif">
                                <x-filament::icon :icon="$acao['icon']" class="neo-icon-sm" />
                                {{ $acao['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @empty
            <div class="col-span-full py-12 text-center bg-white rounded-2xl border border-gray-100 text-slate-500">
                Nenhuma piscina ativa configurada no momento.
            </div>
        @endforelse
    </div>
</x-filament-widgets::widget>
