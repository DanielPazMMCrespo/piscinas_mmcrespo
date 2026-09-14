<x-filament-widgets::widget>
    <div x-data="painelPiscinasController()"
         @keydown.escape.window="closeActions()"
         class="space-y-6 select-none font-sans">

        <!-- =====================================================================
             1. Main Header & Quick Links
             ===================================================================== -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-2">
                    <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span class="text-xs font-semibold tracking-wider uppercase text-slate-400 dark:text-slate-500">Telemetria em Tempo Real</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 dark:text-white mt-0.5">Painel de Controlo</h2>
                <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 font-medium mt-0.5">Visão global das piscinas · Conforme norma portuguesa CN 14/DA</p>
            </div>

            <div class="flex items-center gap-2.5 self-start sm:self-auto">
                @unless ($isNS)
                    <a href="{{ \App\Filament\Pages\InspecaoDgs::getUrl() }}"
                       class="apple-action-btn px-4 py-2 bg-slate-900 hover:bg-black text-white dark:bg-slate-800 dark:hover:bg-slate-700 shadow-sm min-h-[44px]">
                        <x-filament::icon icon="heroicon-o-shield-check" class="w-4 h-4 text-emerald-400" />
                        <span>Modo Inspeção DGS</span>
                    </a>
                @endunless
            </div>
        </div>

        @if ($totalPiscinas > 0)
            <!-- =====================================================================
                 2. Dynamic Fleet Capsule (Status Global + Filtros + Seletor de Modo)
                 ===================================================================== -->
            <div class="fleet-capsule p-3 sm:p-4 flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-4">
                <!-- Fleet Compliance Status Summary -->
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex items-center gap-3.5">
                        @php
                            $todosConformes = $conformes === $totalPiscinas && $totalPiscinas > 0;
                            $numAlertas = $totalPiscinas - $conformes;
                        @endphp
                        <div class="w-11 h-11 rounded-2xl flex-shrink-0 flex items-center justify-center font-extrabold text-base text-white shadow-md {{ $todosConformes ? 'bg-gradient-to-br from-emerald-500 to-teal-600 shadow-emerald-500/20' : 'bg-gradient-to-br from-rose-500 to-amber-600 shadow-rose-500/20' }}">
                            {{ $conformes }}/{{ $totalPiscinas }}
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-slate-900 dark:text-white">Piscinas Conformes</span>
                                @if ($todosConformes)
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20">
                                        100% Conforme ✓
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-rose-500/10 text-rose-600 dark:text-rose-400 border border-rose-500/20 flex items-center gap-1">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-ping"></span>
                                        {{ $numAlertas }} em Alerta
                                    </span>
                                @endif
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                @if ($todosConformes)
                                    Todas as piscinas abertas operam dentro dos limites legais.
                                @else
                                    Existem parâmetros fora da banda regulamentar recomendada.
                                @endif
                            </p>
                        </div>
                    </div>

                    @unless ($isNS)
                        <div class="hidden sm:flex items-center gap-3 pl-4 border-l border-slate-200/80 dark:border-white/10">
                            <div class="w-9 h-9 rounded-xl flex-shrink-0 flex items-center justify-center font-bold text-xs bg-slate-100 dark:bg-white/10 text-slate-700 dark:text-slate-300">
                                {{ $registadasHoje }}/{{ $totalPiscinas }}
                            </div>
                            <div>
                                <span class="text-xs font-bold text-slate-900 dark:text-white block">Registos Hoje</span>
                                <span class="text-[11px] text-slate-400 dark:text-slate-500">{{ round($percentagemRegisto) }}% concluído</span>
                            </div>
                        </div>
                    @endunless
                </div>

                <!-- Controls: Filter Segments + View Mode Switcher -->
                <div class="flex flex-wrap sm:flex-nowrap items-center gap-2 justify-between lg:justify-end">
                    <!-- Filter Segmented Buttons -->
                    <div class="inline-flex items-center p-1 rounded-xl bg-slate-100 dark:bg-white/[0.06] border border-slate-200/80 dark:border-white/5 text-xs font-semibold">
                        <button type="button"
                                @click="setFilter('all')"
                                :class="filterStatus === 'all' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                                class="px-3 py-1.5 rounded-lg transition-all min-h-[36px] flex items-center">
                            Todas ({{ $totalPiscinas }})
                        </button>
                        <button type="button"
                                @click="setFilter('attention')"
                                :class="filterStatus === 'attention' ? 'bg-white dark:bg-slate-800 text-rose-600 dark:text-rose-400 shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-rose-600 dark:hover:text-rose-400'"
                                class="px-3 py-1.5 rounded-lg transition-all min-h-[36px] flex items-center gap-1.5">
                            @if ($numAlertas > 0)
                                <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                            @endif
                            Atenção ({{ $numAlertas }})
                        </button>
                        <button type="button"
                                @click="setFilter('ok')"
                                :class="filterStatus === 'ok' ? 'bg-white dark:bg-slate-800 text-emerald-600 dark:text-emerald-400 shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-emerald-600 dark:hover:text-emerald-400'"
                                class="px-3 py-1.5 rounded-lg transition-all min-h-[36px] flex items-center">
                            Conformes ({{ $conformes }})
                        </button>
                    </div>

                    <!-- View Mode Switcher: Cartões vs Cockpit -->
                    <div class="inline-flex items-center p-1 rounded-xl bg-slate-100 dark:bg-white/[0.06] border border-slate-200/80 dark:border-white/5 text-xs font-semibold">
                        <button type="button"
                                @click="setViewMode('cards')"
                                :class="viewMode === 'cards' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                                class="px-3 py-1.5 rounded-lg transition-all min-h-[36px] flex items-center gap-1.5"
                                title="Vista de Cartões Visuais">
                            <x-filament::icon icon="heroicon-m-squares-2x2" class="w-4 h-4" />
                            <span class="hidden sm:inline">Cartões</span>
                        </button>
                        <button type="button"
                                @click="setViewMode('cockpit')"
                                :class="viewMode === 'cockpit' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-sm' : 'text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'"
                                class="px-3 py-1.5 rounded-lg transition-all min-h-[36px] flex items-center gap-1.5"
                                title="Cockpit Compacto 1-Ecrã">
                            <x-filament::icon icon="heroicon-m-table-cells" class="w-4 h-4" />
                            <span class="hidden sm:inline">Cockpit</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- =====================================================================
             3. VIEW 1: APPLE × TESLA VISUAL CARDS GRID
             ===================================================================== -->
        <div x-show="viewMode === 'cards'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">

            @forelse ($piscinas as $item)
                @php
                    $piscina = $item['piscina'];
                    $conformesList = $item['parametros_conformes'];
                    $numFora = count(array_filter($conformesList, fn ($ok) => $ok === false));
                    $temDados = $item['tem_dados_conformes'];
                    $isEncerrada = ! empty($item['encerramento']);
                    $isFaltaRegistar = empty($item['encerramento']) && ! empty($item['sem_hoje']);
                    $isAlert = $temDados && $numFora > 0;
                    $isOk = $temDados && $numFora === 0 && ! $isFaltaRegistar && ! $isEncerrada;

                    // Halo styling
                    $haloClass = match (true) {
                        $isAlert => 'apple-card-halo--alert',
                        $isFaltaRegistar => 'apple-card-halo--warning',
                        $isEncerrada => 'apple-card-halo--neutral',
                        $isOk => 'apple-card-halo--ok',
                        default => 'apple-card-halo--neutral',
                    };

                    // Header badge styling
                    $forasLabels = collect($item['metricas4'] ?? [])
                        ->filter(fn ($m) => ($m['ok'] ?? null) === false)
                        ->pluck('label')
                        ->implode(', ');
                @endphp

                <div class="apple-card p-5 flex flex-col justify-between"
                     wire:key="pool-card-{{ $piscina->id }}"
                     x-show="matchesFilter({{ $isAlert ? 'true' : 'false' }}, {{ $isOk ? 'true' : 'false' }})"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 scale-98"
                     x-transition:enter-end="opacity-100 scale-100">

                    <!-- Top Status Halo Line -->
                    <div class="apple-card-halo {{ $haloClass }}"></div>

                    <div>
                        <!-- Pool Header: Title + Status Pill + Hanna Latency -->
                        <div class="flex items-start justify-between gap-3 mb-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <h3 class="text-lg font-bold text-slate-900 dark:text-white tracking-tight truncate">
                                        {{ $piscina->name }}
                                    </h3>

                                    @if ($isEncerrada)
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-md bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                            Encerrada
                                        </span>
                                    @elseif ($isAlert)
                                        <span class="px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide rounded-md bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/30">
                                            {{ $forasLabels !== '' ? $forasLabels : $numFora.' Alerta'.($numFora > 1 ? 's' : '') }}
                                        </span>
                                    @elseif ($isFaltaRegistar)
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-md bg-amber-500/15 text-amber-700 dark:text-amber-400 border border-amber-500/30">
                                            Falta registar
                                        </span>
                                    @elseif ($isOk)
                                        <span class="px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                            Conforme ✓
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 text-[10px] font-semibold rounded-md bg-slate-100 text-slate-600 dark:bg-white/5 dark:text-slate-400 border border-slate-200 dark:border-white/10">
                                            Sem dados
                                        </span>
                                    @endif
                                </div>
                                <div class="text-xs text-slate-400 dark:text-slate-500 font-medium mt-0.5 truncate">
                                    {{ $piscina->instalacao?->name ?? 'Instalação Geral' }}
                                </div>
                            </div>

                            <!-- Live Sonda Pulse Indicator -->
                            @if (! empty($item['sonda']['instalada']))
                                @php
                                    $idadeSonda = $item['sonda']['idade_min'];
                                    $avariaSonda = $item['sonda']['avaria'] ?? null;
                                    $sondaOk = ! $avariaSonda && $idadeSonda !== null && $idadeSonda <= 60;
                                @endphp
                                <div class="flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 dark:bg-white/5 text-[10px] font-medium border border-slate-200/80 dark:border-white/10 flex-shrink-0"
                                     title="{{ $avariaSonda ? 'Sonda indisponível: ' . $avariaSonda['motivo'] : ($idadeSonda !== null ? 'Leitura há ' . $idadeSonda . ' min' : 'Sem leituras') }}">
                                    <span class="w-2 h-2 rounded-full {{ $sondaOk ? 'bg-emerald-500' : ($avariaSonda ? 'bg-amber-500' : 'bg-rose-500') }}"></span>
                                    <span class="text-slate-600 dark:text-slate-300">
                                        @if ($avariaSonda)
                                            Avaria
                                        @elseif ($idadeSonda === null)
                                            Sem dados
                                        @elseif ($idadeSonda < 1)
                                            Hanna agora
                                        @elseif ($idadeSonda < 60)
                                            Hanna {{ $idadeSonda }}m
                                        @else
                                            Hanna {{ (int) floor($idadeSonda / 60) }}h
                                        @endif
                                    </span>
                                </div>
                            @endif
                        </div>

                        <!-- Encerrada Message Banner -->
                        @if ($isEncerrada)
                            <div class="mb-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/40 p-3 text-xs text-slate-600 dark:text-slate-300">
                                <div class="flex items-center gap-1.5 font-semibold text-slate-700 dark:text-slate-200">
                                    <x-filament::icon icon="heroicon-m-lock-closed" class="w-4 h-4 text-slate-500" />
                                    <span>Encerrada {{ $item['encerramento']['periodo'] }}</span>
                                </div>
                                <div class="mt-1 text-[11px]">
                                    {{ $item['encerramento']['motivo'] }} ·
                                    {{ $item['encerramento']['agua_em_tratamento'] ? 'água em tratamento, registos possíveis' : 'piscina parada' }}
                                </div>
                            </div>
                        @endif

                        <!-- =================================================================
                             HERO TELEMETRY: 3 Tesla Range Gauges (pH, Redox/ORP, Cloro Livre)
                             ================================================================= -->
                        <div class="space-y-4 my-4 p-4 rounded-2xl bg-slate-50/80 dark:bg-white/[0.02] border border-slate-200/70 dark:border-white/5">

                            <!-- Metric 1: pH (Tesla Range Gauge) -->
                            @php
                                $mPh = $item['metricas4']['ph'];
                                $gPh = $mPh['gauge'] ?? null;
                            @endphp
                            <div>
                                <div class="flex items-baseline justify-between mb-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">pH</span>
                                        @if ($mPh['ok'] === false)
                                            <span class="text-[11px] text-rose-600 dark:text-rose-400 font-bold">▲ Fora do Limite</span>
                                        @elseif ($mPh['ok'] === true)
                                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">Conforme</span>
                                        @endif
                                    </div>
                                    <div class="text-lg font-black font-mono {{ $mPh['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                        {{ $mPh['valor'] }}
                                        <span class="text-[11px] font-normal text-slate-400 dark:text-slate-500">/ {{ $mPh['limite_resumo'] ?? '6,9–8,0' }}</span>
                                    </div>
                                </div>

                                @if ($gPh)
                                    <!-- Tesla Linear Range Track -->
                                    <div class="tesla-gauge-track" title="{{ $mPh['tooltip'] }}">
                                        <!-- Legal target band (6.9 to 8.0) -->
                                        <div class="tesla-gauge-target" style="left: {{ $gPh['target_start_percent'] }}%; width: {{ $gPh['target_width_percent'] }}%;"></div>
                                        @if ($gPh['sweet_band'])
                                            <!-- Sweet spot highlight (7.2 to 7.6) -->
                                            <div class="tesla-gauge-sweet" style="left: {{ $gPh['sweet_band']['start_percent'] }}%; width: {{ $gPh['sweet_band']['width_percent'] }}%;"></div>
                                        @endif
                                        <!-- Floating Needle -->
                                        <div class="tesla-gauge-needle {{ $gPh['status'] === 'ok' ? 'tesla-gauge-needle--ok' : 'tesla-gauge-needle--alert' }}"
                                             style="left: {{ $gPh['percent'] }}%;"></div>
                                    </div>
                                    <div class="flex justify-between text-[9px] text-slate-400 dark:text-slate-500 mt-1 font-mono">
                                        <span>6,5 (Ácido)</span>
                                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">Alvo 7,2 – 7,6</span>
                                        <span>8,5 (Básico)</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Metric 2: Cloro Livre (Tesla Range Gauge) -->
                            @php
                                $mLivre = $item['metricas4']['livre'];
                                $gLivre = $mLivre['gauge'] ?? null;
                            @endphp
                            <div class="pt-3 border-t border-slate-200/60 dark:border-white/5">
                                <div class="flex items-baseline justify-between mb-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">Cloro Livre</span>
                                        @if ($mLivre['ok'] === false)
                                            <span class="text-[11px] text-rose-600 dark:text-rose-400 font-bold">▲ Fora do Limite</span>
                                        @elseif ($mLivre['ok'] === true)
                                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">Conforme</span>
                                        @endif
                                    </div>
                                    <div class="text-lg font-black font-mono {{ $mLivre['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                        {{ $mLivre['valor'] }}
                                        @if (!empty($mLivre['limite_resumo']))
                                            <span class="text-[11px] font-normal text-slate-400 dark:text-slate-500">/ {{ $mLivre['limite_resumo'] }}</span>
                                        @endif
                                    </div>
                                </div>

                                @if ($gLivre)
                                    <div class="tesla-gauge-track" title="{{ $mLivre['tooltip'] }}">
                                        <div class="tesla-gauge-target" style="left: {{ $gLivre['target_start_percent'] }}%; width: {{ $gLivre['target_width_percent'] }}%;"></div>
                                        <div class="tesla-gauge-needle {{ $gLivre['status'] === 'ok' ? 'tesla-gauge-needle--ok' : 'tesla-gauge-needle--alert' }}"
                                             style="left: {{ $gLivre['percent'] }}%;"></div>
                                    </div>
                                    <div class="flex justify-between text-[9px] text-slate-400 dark:text-slate-500 mt-1 font-mono">
                                        <span>0,0</span>
                                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">Banda CN 14/DA</span>
                                        <span>{{ number_format($gLivre['scale_max'], 1, ',', '') }} mg/L</span>
                                    </div>
                                @endif
                            </div>

                            <!-- Metric 3: Redox / ORP (Tesla Range Gauge) -->
                            @php
                                $mRedox = $item['metricas4']['redox'];
                                $gRedox = $mRedox['gauge'] ?? null;
                            @endphp
                            <div class="pt-3 border-t border-slate-200/60 dark:border-white/5">
                                <div class="flex items-baseline justify-between mb-1.5">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">Redox (ORP)</span>
                                        @if ($mRedox['ok'] === false)
                                            <span class="text-[11px] text-rose-600 dark:text-rose-400 font-bold">▲ Desvio</span>
                                        @elseif ($mRedox['ok'] === true)
                                            <span class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium">Conforme</span>
                                        @endif
                                    </div>
                                    <div class="text-lg font-black font-mono {{ $mRedox['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                        {{ $mRedox['valor'] }}
                                        <span class="text-[11px] font-normal text-slate-400 dark:text-slate-500">/ {{ $mRedox['limite_resumo'] ?? '660–750 mV' }}</span>
                                    </div>
                                </div>

                                @if ($gRedox)
                                    <div class="tesla-gauge-track" title="{{ $mRedox['tooltip'] }}">
                                        <div class="tesla-gauge-target" style="left: {{ $gRedox['target_start_percent'] }}%; width: {{ $gRedox['target_width_percent'] }}%;"></div>
                                        <div class="tesla-gauge-needle {{ $gRedox['status'] === 'ok' ? 'tesla-gauge-needle--ok' : 'tesla-gauge-needle--alert' }}"
                                             style="left: {{ $gRedox['percent'] }}%;"></div>
                                    </div>
                                    <div class="flex justify-between text-[9px] text-slate-400 dark:text-slate-500 mt-1 font-mono">
                                        <span>{{ (int) $gRedox['scale_min'] }}</span>
                                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">Alvo {{ (int) $gRedox['target_min'] }}–{{ (int) $gRedox['target_max'] }}</span>
                                        <span>{{ (int) $gRedox['scale_max'] }} mV</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Secondary Metrics: Mini Glass Ribbon -->
                        <div class="grid grid-cols-3 gap-2 mb-4">
                            @php
                                $mComb = $item['metricas4']['combinado'];
                                $mTemp = $item['metricas4']['temp'];
                                $mTurb = $item['metricas4']['turbidez'];
                            @endphp

                            <!-- Cloro Combinado -->
                            <div class="glass-pill {{ $mComb['ok'] === false ? 'glass-pill--alert' : '' }} text-center" title="{{ $mComb['tooltip'] }}">
                                <span class="block text-[10px] text-slate-400 dark:text-slate-500 uppercase font-bold tracking-tight">Cl. Comb</span>
                                <span class="text-xs font-bold font-mono {{ $mComb['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' }}">
                                    {{ $mComb['valor'] !== '—' ? str_replace(' mg/L', '', $mComb['valor']) : '—' }}
                                </span>
                                <span class="block text-[9px] {{ $mComb['ok'] === false ? 'text-rose-600 dark:text-rose-400 font-bold' : 'text-slate-400 dark:text-slate-500' }}">
                                    {{ $mComb['limite_resumo'] }}
                                </span>
                            </div>

                            <!-- Temperatura -->
                            <div class="glass-pill {{ $mTemp['ok'] === false ? 'glass-pill--alert' : '' }} text-center" title="{{ $mTemp['tooltip'] }}">
                                <span class="block text-[10px] text-slate-400 dark:text-slate-500 uppercase font-bold tracking-tight">Temp.</span>
                                <span class="text-xs font-bold font-mono {{ $mTemp['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' }}">
                                    {{ $mTemp['valor'] }}
                                </span>
                                <span class="block text-[9px] {{ $mTemp['ok'] === false ? 'text-rose-600 dark:text-rose-400 font-bold' : 'text-slate-400 dark:text-slate-500' }}">
                                    {{ $mTemp['limite_resumo'] ?? '26–30 °C' }}
                                </span>
                            </div>

                            <!-- Turbidez -->
                            <div class="glass-pill {{ $mTurb['ok'] === false ? 'glass-pill--alert' : '' }} text-center" title="{{ $mTurb['tooltip'] }}">
                                <span class="block text-[10px] text-slate-400 dark:text-slate-500 uppercase font-bold tracking-tight">Turbidez</span>
                                <span class="text-xs font-bold font-mono {{ $mTurb['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' }}">
                                    {{ $mTurb['valor'] !== '—' ? str_replace(' FNU', '', $mTurb['valor']) : '—' }}
                                </span>
                                <span class="block text-[9px] {{ $mTurb['ok'] === false ? 'text-rose-600 dark:text-rose-400 font-bold' : 'text-slate-400 dark:text-slate-500' }}">
                                    {{ $mTurb['limite_resumo'] }}
                                </span>
                            </div>
                        </div>

                        <!-- Bidões de Químicos (Tesla Battery Bar Style) -->
                        @if (! $isNS && ! empty($item['bidoes']))
                            <div class="space-y-2 mb-4">
                                @foreach ($item['bidoes'] as $bidao)
                                    @php
                                        $isCritico = $bidao['status'] === 'critico' || $bidao['esta_baixo'];
                                        $isAviso = $bidao['status'] === 'aviso' || $bidao['esgota_fim_de_semana'];
                                        $perc = $bidao['percentagem'] ?? 0;
                                        $barColor = $isCritico ? 'bg-rose-500' : ($isAviso ? 'bg-amber-500' : 'bg-emerald-500');
                                    @endphp
                                    <div class="flex items-center justify-between text-xs py-1.5 px-3 rounded-xl bg-slate-50 dark:bg-white/[0.02] border border-slate-200/60 dark:border-white/5"
                                         title="{{ $bidao['label'] }}: {{ $bidao['descricao_autonomia'] }}">
                                        <span class="text-[11px] font-medium text-slate-500 dark:text-slate-400">{{ $bidao['label'] }}:</span>
                                        <div class="flex items-center gap-2">
                                            <div class="w-16 h-2 rounded-full bg-slate-200 dark:bg-white/10 overflow-hidden">
                                                <div class="{{ $barColor }} h-full rounded-full transition-all duration-300" style="width: {{ $perc }}%"></div>
                                            </div>
                                            <span class="font-mono font-bold text-[11px] {{ $isCritico ? 'text-rose-500' : ($isAviso ? 'text-amber-500' : 'text-slate-700 dark:text-slate-300') }}">
                                                {{ $perc }}% ({{ $bidao['descricao_autonomia'] }})
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <!-- Last Manual Record Footnote -->
                        @if (! empty($item['ultimo_registo_manual']))
                            <div class="text-[11px] text-slate-400 dark:text-slate-500 mb-3 flex items-center gap-1.5 truncate">
                                <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                                <span>
                                    Análise manual por <strong class="font-medium text-slate-700 dark:text-slate-300">{{ $item['ultimo_registo_manual']['autor'] }}</strong>
                                    · {{ $item['ultimo_registo_manual']['idade'] }}
                                    @if (!empty($item['ultimo_registo_manual']['hora']))
                                        ({{ $item['ultimo_registo_manual']['hora'] }})
                                    @endif
                                </span>
                            </div>
                        @endif
                    </div>

                    <!-- =================================================================
                         ACTIONS FOOTER: Apple Action Buttons (Thumb-Zone >= 48px)
                         ================================================================= -->
                    <div class="pt-3 border-t border-slate-200/60 dark:border-white/5 flex items-center gap-2">
                        @if ($item['pode_registar'])
                            <a href="{{ $item['url_registar'] }}"
                               class="apple-action-btn flex-1 bg-slate-900 hover:bg-black text-white dark:bg-white dark:hover:bg-slate-200 dark:text-slate-900 shadow-sm">
                                <x-filament::icon icon="heroicon-m-beaker" class="w-4 h-4" />
                                <span>Registar Água</span>
                            </a>
                        @else
                            <button type="button" disabled
                                    class="apple-action-btn flex-1 bg-slate-100 text-slate-400 dark:bg-white/5 dark:text-slate-600 cursor-not-allowed">
                                <x-filament::icon icon="heroicon-m-beaker" class="w-4 h-4" />
                                <span>Registar Água</span>
                            </button>
                        @endif

                        <!-- Action Sheet Trigger (···) -->
                        <button type="button"
                                @click="openActions({
                                    id: {{ $piscina->id }},
                                    name: '{{ addslashes($piscina->name) }}',
                                    instalacao: '{{ addslashes($piscina->instalacao?->name ?? 'Instalação Geral') }}',
                                    acoes: {{ json_encode($item['acoes_rapidas']) }}
                                })"
                                class="apple-action-btn min-w-[48px] px-3 bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-white/5 dark:hover:bg-white/10 dark:text-slate-300 border border-slate-200/60 dark:border-white/5"
                                title="Mais ações operacionais">
                            <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="w-5 h-5" />
                        </button>
                    </div>

                </div>
            @empty
                <div class="col-span-full py-16 text-center apple-card text-slate-500">
                    <x-filament::icon icon="heroicon-o-information-circle" class="w-8 h-8 mx-auto text-slate-400 mb-2" />
                    <p class="font-medium">Nenhuma piscina ativa configurada no momento.</p>
                </div>
            @endforelse

        </div>

        <!-- =====================================================================
             4. VIEW 2: COCKPIT COMPACT MODE (1-ECRÃ SUPERVISION)
             ===================================================================== -->
        <div x-show="viewMode === 'cockpit'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-1"
             x-transition:enter-end="opacity-100 translate-y-0"
             class="apple-card overflow-hidden">

            <!-- Desktop Telemetry Table (md+) -->
            <div class="hidden md:block overflow-x-auto no-scrollbar">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-slate-200/80 dark:border-white/10 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 bg-slate-50/50 dark:bg-white/[0.02]">
                            <th class="py-3.5 px-4">Piscina</th>
                            <th class="py-3.5 px-4">Estado</th>
                            <th class="py-3.5 px-4 min-w-[190px]">pH (Tolerância)</th>
                            <th class="py-3.5 px-4 min-w-[190px]">Cloro Livre</th>
                            <th class="py-3.5 px-4">Redox (ORP)</th>
                            <th class="py-3.5 px-4">Temp.</th>
                            <th class="py-3.5 px-4">Sonda</th>
                            <th class="py-3.5 px-4 text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200/60 dark:divide-white/5 text-xs">
                        @foreach ($piscinas as $item)
                            @php
                                $piscina = $item['piscina'];
                                $conformesList = $item['parametros_conformes'];
                                $numFora = count(array_filter($conformesList, fn ($ok) => $ok === false));
                                $temDados = $item['tem_dados_conformes'];
                                $isEncerrada = ! empty($item['encerramento']);
                                $isFaltaRegistar = empty($item['encerramento']) && ! empty($item['sem_hoje']);
                                $isAlert = $temDados && $numFora > 0;
                                $isOk = $temDados && $numFora === 0 && ! $isFaltaRegistar && ! $isEncerrada;

                                $mPh = $item['metricas4']['ph'];
                                $gPh = $mPh['gauge'] ?? null;

                                $mLivre = $item['metricas4']['livre'];
                                $gLivre = $mLivre['gauge'] ?? null;

                                $mRedox = $item['metricas4']['redox'];
                                $mTemp = $item['metricas4']['temp'];
                            @endphp
                            <tr wire:key="cockpit-row-{{ $piscina->id }}"
                                x-show="matchesFilter({{ $isAlert ? 'true' : 'false' }}, {{ $isOk ? 'true' : 'false' }})"
                                class="hover:bg-slate-50/75 dark:hover:bg-white/[0.02] transition-colors">

                                <!-- Pool Name & Installation -->
                                <td class="py-3 px-4 font-bold text-slate-900 dark:text-white">
                                    <div class="truncate">{{ $piscina->name }}</div>
                                    <div class="text-[10px] font-normal text-slate-400 truncate">{{ $piscina->instalacao?->name ?? 'Geral' }}</div>
                                </td>

                                <!-- Status Badge -->
                                <td class="py-3 px-4">
                                    @if ($isEncerrada)
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-md bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 border border-slate-200 dark:border-slate-700">
                                            Encerrada
                                        </span>
                                    @elseif ($isAlert)
                                        <span class="px-2 py-0.5 text-[10px] font-extrabold uppercase rounded-md bg-rose-500/15 text-rose-600 dark:text-rose-400 border border-rose-500/30">
                                            Alerta
                                        </span>
                                    @elseif ($isFaltaRegistar)
                                        <span class="px-2 py-0.5 text-[10px] font-bold rounded-md bg-amber-500/15 text-amber-700 dark:text-amber-400 border border-amber-500/30">
                                            Falta
                                        </span>
                                    @elseif ($isOk)
                                        <span class="px-2 py-0.5 text-[10px] font-extrabold uppercase rounded-md bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border border-emerald-500/30">
                                            OK ✓
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 text-[10px] font-medium rounded-md bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400">
                                            Sem dados
                                        </span>
                                    @endif
                                </td>

                                <!-- pH Gauge in Table Cell -->
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2.5">
                                        <span class="font-mono font-bold text-sm w-10 text-right {{ $mPh['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                            {{ $mPh['valor'] }}
                                        </span>
                                        @if ($gPh)
                                            <div class="w-24 tesla-gauge-track">
                                                <div class="tesla-gauge-target" style="left: {{ $gPh['target_start_percent'] }}%; width: {{ $gPh['target_width_percent'] }}%;"></div>
                                                <div class="tesla-gauge-needle {{ $gPh['status'] === 'ok' ? 'tesla-gauge-needle--ok' : 'tesla-gauge-needle--alert' }}"
                                                     style="left: {{ $gPh['percent'] }}%;"></div>
                                            </div>
                                        @endif
                                    </div>
                                </td>

                                <!-- Cloro Livre Gauge in Table Cell -->
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2.5">
                                        <span class="font-mono font-bold text-sm w-12 text-right {{ $mLivre['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">
                                            {{ $mLivre['valor'] !== '—' ? str_replace(' mg/L', '', $mLivre['valor']) : '—' }}
                                        </span>
                                        @if ($gLivre)
                                            <div class="w-24 tesla-gauge-track">
                                                <div class="tesla-gauge-target" style="left: {{ $gLivre['target_start_percent'] }}%; width: {{ $gLivre['target_width_percent'] }}%;"></div>
                                                <div class="tesla-gauge-needle {{ $gLivre['status'] === 'ok' ? 'tesla-gauge-needle--ok' : 'tesla-gauge-needle--alert' }}"
                                                     style="left: {{ $gLivre['percent'] }}%;"></div>
                                            </div>
                                        @endif
                                    </div>
                                </td>

                                <!-- Redox / ORP -->
                                <td class="py-3 px-4 font-mono font-bold text-xs {{ $mRedox['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-800 dark:text-slate-200' }}">
                                    {{ $mRedox['valor'] }}
                                </td>

                                <!-- Temperatura -->
                                <td class="py-3 px-4 font-mono font-medium text-xs text-slate-700 dark:text-slate-300">
                                    {{ $mTemp['valor'] }}
                                </td>

                                <!-- Sonda Status -->
                                <td class="py-3 px-4 text-[11px] text-slate-500 dark:text-slate-400">
                                    @if (! empty($item['sonda']['instalada']))
                                        @php
                                            $idS = $item['sonda']['idade_min'];
                                            $avS = $item['sonda']['avaria'] ?? null;
                                        @endphp
                                        <span class="inline-flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full {{ $avS ? 'bg-amber-500' : ($idS !== null && $idS <= 60 ? 'bg-emerald-500' : 'bg-rose-500') }}"></span>
                                            {{ $avS ? 'Avaria' : ($idS !== null ? ($idS < 1 ? 'Agora' : $idS . 'm') : 'Sem leitura') }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </td>

                                <!-- Direct Action Buttons -->
                                <td class="py-3 px-4 text-right">
                                    <div class="inline-flex items-center gap-1.5 justify-end">
                                        @if ($item['pode_registar'])
                                            <a href="{{ $item['url_registar'] }}"
                                               class="px-3 py-1.5 rounded-lg bg-slate-900 hover:bg-black text-white dark:bg-white dark:hover:bg-slate-200 dark:text-slate-900 font-bold text-xs shadow-sm min-h-[36px] flex items-center gap-1">
                                                <x-filament::icon icon="heroicon-m-beaker" class="w-3.5 h-3.5" />
                                                <span>Registar</span>
                                            </a>
                                        @endif
                                        <button type="button"
                                                @click="openActions({
                                                    id: {{ $piscina->id }},
                                                    name: '{{ addslashes($piscina->name) }}',
                                                    instalacao: '{{ addslashes($piscina->instalacao?->name ?? 'Instalação Geral') }}',
                                                    acoes: {{ json_encode($item['acoes_rapidas']) }}
                                                })"
                                                class="p-1.5 rounded-lg text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-white/10 min-h-[36px] min-w-[36px] flex items-center justify-center"
                                                title="Mais opções">
                                            <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cockpit Condensed List (< md) -->
            <div class="block md:hidden divide-y divide-slate-200/60 dark:divide-white/5">
                @foreach ($piscinas as $item)
                    @php
                        $piscina = $item['piscina'];
                        $conformesList = $item['parametros_conformes'];
                        $numFora = count(array_filter($conformesList, fn ($ok) => $ok === false));
                        $temDados = $item['tem_dados_conformes'];
                        $isEncerrada = ! empty($item['encerramento']);
                        $isFaltaRegistar = empty($item['encerramento']) && ! empty($item['sem_hoje']);
                        $isAlert = $temDados && $numFora > 0;
                        $isOk = $temDados && $numFora === 0 && ! $isFaltaRegistar && ! $isEncerrada;

                        $mPh = $item['metricas4']['ph'];
                        $mLivre = $item['metricas4']['livre'];
                        $mRedox = $item['metricas4']['redox'];
                    @endphp
                    <div wire:key="cockpit-mobile-{{ $piscina->id }}"
                         x-show="matchesFilter({{ $isAlert ? 'true' : 'false' }}, {{ $isOk ? 'true' : 'false' }})"
                         @click="openActions({
                             id: {{ $piscina->id }},
                             name: '{{ addslashes($piscina->name) }}',
                             instalacao: '{{ addslashes($piscina->instalacao?->name ?? 'Instalação Geral') }}',
                             acoes: {{ json_encode($item['acoes_rapidas']) }}
                         })"
                         class="p-4 flex items-center justify-between gap-3 active:bg-slate-50 dark:active:bg-white/5 cursor-pointer">
                        <div class="min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="w-2 h-2 rounded-full {{ $isAlert ? 'bg-rose-500' : ($isOk ? 'bg-emerald-500' : 'bg-slate-400') }}"></span>
                                <span class="font-bold text-sm text-slate-900 dark:text-white truncate">{{ $piscina->name }}</span>
                            </div>
                            <div class="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5 truncate">{{ $piscina->instalacao?->name ?? 'Geral' }}</div>
                        </div>

                        <!-- Telemetry Triad -->
                        <div class="flex items-center gap-3 text-right font-mono text-xs">
                            <div>
                                <span class="block text-[9px] text-slate-400 uppercase">pH</span>
                                <span class="font-bold {{ $mPh['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">{{ $mPh['valor'] }}</span>
                            </div>
                            <div>
                                <span class="block text-[9px] text-slate-400 uppercase">Cl. Livre</span>
                                <span class="font-bold {{ $mLivre['ok'] === false ? 'text-rose-600 dark:text-rose-400' : 'text-slate-900 dark:text-white' }}">{{ $mLivre['valor'] !== '—' ? str_replace(' mg/L', '', $mLivre['valor']) : '—' }}</span>
                            </div>
                            <div>
                                <span class="block text-[9px] text-slate-400 uppercase">Redox</span>
                                <span class="font-bold text-slate-700 dark:text-slate-300">{{ $mRedox['valor'] !== '—' ? str_replace(' mV', '', $mRedox['valor']) : '—' }}</span>
                            </div>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="w-4 h-4 text-slate-400" />
                        </div>
                    </div>
                @endforeach
            </div>

        </div>

        <!-- =====================================================================
             5. RESPONSIVE ACTION SHEET (MOBILE BOTTOM DRAWER / DESKTOP POPOVER)
             ===================================================================== -->
        <div x-show="activeSheet !== null"
             x-cloak
             class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
             role="dialog"
             aria-modal="true">

            <!-- Backdrop with iOS blur -->
            <div x-show="activeSheet !== null"
                 x-transition:enter="transition-opacity ease-out duration-200"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-in duration-150"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 @click="closeActions()"
                 class="fixed inset-0 bg-black/60 backdrop-blur-sm"></div>

            <!-- Drawer Container -->
            <div x-show="activeSheet !== null"
                 x-transition:enter="transition ease-out duration-250"
                 x-transition:enter-start="translate-y-full sm:translate-y-0 sm:scale-95 sm:opacity-0"
                 x-transition:enter-end="translate-y-0 sm:scale-100 sm:opacity-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="translate-y-0 sm:scale-100 sm:opacity-100"
                 x-transition:leave-end="translate-y-full sm:translate-y-0 sm:scale-95 sm:opacity-0"
                 class="w-full sm:max-w-md bg-white dark:bg-slate-900 rounded-t-3xl sm:rounded-3xl shadow-2xl border border-slate-200 dark:border-white/10 p-5 z-10 pb-safe">

                <!-- Mobile Grabber Handle -->
                <div class="block sm:hidden w-12 h-1.5 rounded-full bg-slate-300 dark:bg-slate-700 mx-auto mb-4"></div>

                <!-- Header -->
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-200/80 dark:border-white/10">
                    <div>
                        <h3 class="text-lg font-bold text-slate-900 dark:text-white" x-text="activeSheet?.name"></h3>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5" x-text="activeSheet?.instalacao"></p>
                    </div>
                    <button type="button"
                            @click="closeActions()"
                            class="p-2 rounded-full text-slate-400 hover:text-slate-600 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-white/10 transition-colors">
                        <x-filament::icon icon="heroicon-m-x-mark" class="w-5 h-5" />
                    </button>
                </div>

                <!-- Actions List -->
                <div class="space-y-2">
                    <template x-for="(acao, index) in (activeSheet?.acoes || [])" :key="index">
                        <a :href="acao.url"
                           :class="acao.primary ? 'bg-slate-900 text-white hover:bg-black dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200 font-bold' : 'bg-slate-50 text-slate-800 dark:bg-white/5 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-white/10 font-semibold'"
                           class="w-full min-h-[52px] px-4 rounded-2xl flex items-center justify-between transition-all active:scale-98 shadow-sm">
                            <div class="flex items-center gap-3">
                                <span class="p-2 rounded-xl" :class="acao.primary ? 'bg-white/15 dark:bg-black/10' : 'bg-slate-200/60 dark:bg-white/10'">
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="w-4 h-4" />
                                </span>
                                <span class="text-sm" x-text="acao.label"></span>
                            </div>
                            <x-filament::icon icon="heroicon-m-arrow-right" class="w-4 h-4 opacity-50" />
                        </a>
                    </template>

                    <template x-if="!activeSheet?.acoes || activeSheet?.acoes.length === 0">
                        <p class="text-xs text-slate-400 text-center py-4">Sem ações adicionais disponíveis para o seu perfil.</p>
                    </template>
                </div>

                <!-- Cancel Button for Mobile Thumb Zone -->
                <button type="button"
                        @click="closeActions()"
                        class="w-full min-h-[48px] mt-4 rounded-2xl bg-slate-100 hover:bg-slate-200 dark:bg-white/5 dark:hover:bg-white/10 text-slate-600 dark:text-slate-400 font-bold text-xs transition-colors">
                    Fechar
                </button>
            </div>
        </div>

    </div>

    <!-- Centralized Alpine Controller -->
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('painelPiscinasController', () => ({
                viewMode: 'cards',
                filterStatus: 'all',
                activeSheet: null,

                init() {
                    try {
                        const savedView = localStorage.getItem('mmc_pool_view_mode');
                        if (savedView === 'cards' || savedView === 'cockpit') {
                            this.viewMode = savedView;
                        }
                    } catch (e) {}
                },

                setViewMode(mode) {
                    this.viewMode = mode;
                    try {
                        localStorage.setItem('mmc_pool_view_mode', mode);
                    } catch (e) {}
                },

                setFilter(status) {
                    this.filterStatus = status;
                },

                matchesFilter(isAlert, isOk) {
                    if (this.filterStatus === 'all') return true;
                    if (this.filterStatus === 'attention') return isAlert === true;
                    if (this.filterStatus === 'ok') return isOk === true;
                    return true;
                },

                openActions(data) {
                    this.activeSheet = data;
                    try {
                        document.body.style.overflow = 'hidden';
                    } catch (e) {}
                },

                closeActions() {
                    this.activeSheet = null;
                    try {
                        document.body.style.overflow = '';
                    } catch (e) {}
                }
            }));
        });
    </script>
</x-filament-widgets::widget>
