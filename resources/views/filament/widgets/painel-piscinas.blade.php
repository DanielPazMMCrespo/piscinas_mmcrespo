<x-filament-widgets::widget>
    <!-- Main Header -->
    <div class="text-center mb-6">
        <h2 class="text-xl sm:text-2xl font-bold text-slate-800 dark:text-white uppercase tracking-tight">Painel de Controlo</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Visão global das piscinas.</p>
    </div>

    @if ($totalPiscinas > 0)
        <!-- Top KPIs -->
        <div class="neo-top-kpis">
            @unless ($isNS)
                <div class="neo-kpi-card">
                    <div>
                        <div class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Registos Hoje</div>
                        <div class="text-3xl font-bold text-slate-800 dark:text-white">{{ $registadasHoje }}<span class="text-lg text-slate-400 dark:text-slate-500 font-normal">/{{ $totalPiscinas }}</span></div>
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
                    <div class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Piscinas Conformes</div>
                    <div class="text-3xl font-bold text-slate-800 dark:text-white">{{ $conformes }}<span class="text-lg text-slate-400 dark:text-slate-500 font-normal">/{{ $totalPiscinas }}</span></div>
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

    <!-- Pools Grid -->
    <div class="neo-pool-grid"
         x-data="{
             allOpen: true,
             toggleAll() {
                 this.allOpen = !this.allOpen;
                 this.$dispatch('mmc-toggle-all-pools', { open: this.allOpen });
             }
         }">
         
         <div class="col-span-full flex justify-end mb-[-0.5rem]">
             <button type="button" @click="toggleAll()" class="text-sm font-medium text-blue-600 hover:text-blue-800 transition-colors" x-text="allOpen ? 'Recolher todas' : 'Expandir todas'"></button>
         </div>

        @forelse ($piscinas as $item)
            @php
                $piscina = $item['piscina'];
                $conformes = $item['parametros_conformes'];
                $numFora = count(array_filter($conformes, fn ($ok) => $ok === false));
                $temDados = $item['tem_dados_conformes'];
                $estadoGeral = ! $temDados ? 'neutro' : ($numFora > 0 ? 'bad' : 'ok');
            @endphp
            <div class="neo-pool-card" 
                 wire:key="pool-card-{{ $piscina->id }}"
                 x-data="{
                     open: true,
                     init() {
                         try {
                             const saved = localStorage.getItem('neo_pool_open_' + {{ $piscina->id }});
                             if (saved !== null) {
                                 this.open = saved === 'true';
                             }
                         } catch (e) {}
                     },
                     toggle() {
                         this.open = !this.open;
                         try {
                             localStorage.setItem('neo_pool_open_' + {{ $piscina->id }}, this.open);
                         } catch (e) {}
                     }
                 }"
                 @mmc-toggle-all-pools.window="
                     open = $event.detail.open;
                     try { localStorage.setItem('neo_pool_open_' + {{ $piscina->id }}, open); } catch(e) {}
                 "
                 :class="open ? '' : 'pb-2'">
                
                <!-- Card Header -->
                @php
                    $bgClass = 'bg-slate-50 dark:bg-white/5';
                    $textClass = 'text-slate-400 dark:text-slate-300';
                    $statusLabel = 'Sem Dados';
                    $statusColor = 'text-slate-500 dark:text-slate-400';

                    if ($estadoGeral === 'ok') {
                        $bgClass = 'bg-emerald-50 dark:bg-emerald-900/30';
                        $textClass = 'text-emerald-600 dark:text-emerald-400';
                        $statusLabel = 'Conforme';
                        $statusColor = 'text-emerald-600 dark:text-emerald-400';
                    } elseif ($estadoGeral === 'bad') {
                        $bgClass = 'bg-rose-50 dark:bg-rose-900/30';
                        $textClass = 'text-rose-600 dark:text-rose-400';
                        $statusLabel = $numFora . ' Alerta' . ($numFora > 1 ? 's' : '');
                        $statusColor = 'text-rose-600 dark:text-rose-400 font-bold';
                    }
                @endphp
                <div class="neo-pool-header cursor-pointer select-none" @click="toggle()">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full {{ $bgClass }} flex items-center justify-center {{ $textClass }}">
                            <x-filament::icon icon="heroicon-o-swatch" class="w-6 h-6" />
                        </div>
                        <div>
                            <h3 class="neo-pool-title">{{ $piscina->name }}</h3>
                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">{{ $piscina->instalacao?->name ?? 'Sem Instalação' }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs uppercase tracking-wide {{ $statusColor }}" x-show="!open" x-cloak>{{ $statusLabel }}</span>
                        <button type="button" class="text-slate-400 hover:text-slate-600 p-1 transition-transform" :class="open ? 'rotate-180' : ''">
                            <x-filament::icon icon="heroicon-m-chevron-down" class="w-6 h-6" />
                        </button>
                    </div>
                </div>

                <!-- Collapsible Content -->
                <div x-show="open" x-collapse x-cloak class="flex flex-col gap-4 mt-2">
                    <!-- Metrics Grid 2x2 -->
                    <div class="neo-metrics-grid">
                        @foreach (['ph', 'redox', 'livre', 'combinado', 'temp', 'turbidez'] as $key)
                            @php($metrica = $item['metricas4'][$key])
                            <div class="neo-metric-card @if($metrica['ok'] === false) neo-metric-card--alert @endif">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="neo-metric-label">
                                        <span>{{ $metrica['label'] }}</span>
                                    </div>
                                    <div class="neo-metric-value text-right">
                                        <span>{{ $metrica['valor'] }}</span>
                                    </div>
                                </div>
                                <!-- Sparkline -->
                                @if(isset($metrica['sparkline']) && $metrica['sparkline'])
                                    <svg class="neo-metric-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                                        <path d="{{ $metrica['sparkline']['fill'] }}" fill="{{ $metrica['ok'] === false ? '#ffe4e6' : '#eff6ff' }}" opacity="0.6"/>
                                        <path d="{{ $metrica['sparkline']['stroke'] }}" fill="none" stroke="{{ $metrica['ok'] === false ? '#f43f5e' : '#3b82f6' }}" stroke-width="1.5"/>
                                    </svg>
                                @else
                                    <div class="neo-metric-sparkline"></div>
                                @endif

                                <div class="flex items-center justify-between mt-auto">
                                    <div class="neo-metric-status @if($metrica['ok'] === false) neo-metric-status--bad @elseif($metrica['ok'] === true) neo-metric-status--ok @endif">
                                        @if($metrica['ok'] !== null)
                                            <div class="w-2 h-2 rounded-full @if($metrica['ok'] === false) bg-rose-500 @else bg-emerald-500 @endif"></div>
                                            {{ $metrica['ok'] === false ? 'Alerta' : 'OK' }}
                                        @else
                                            <div class="w-2 h-2 rounded-full bg-slate-300"></div>
                                            N/A
                                        @endif
                                    </div>
                                    @if($metrica['origem'] !== 'sem_dados')
                                        <div class="text-[0.65rem] text-slate-400 dark:text-slate-300 font-medium bg-slate-50 dark:bg-white/5 px-1.5 py-0.5 rounded uppercase tracking-wide">
                                            @if($metrica['origem'] === 'controlador')
                                                Sonda • {{ $metrica['idade'] }}
                                            @elseif($metrica['origem'] === 'manual')
                                                Manual • {{ $metrica['idade'] }}
                                            @elseif($metrica['origem'] === 'artefacto')
                                                Lavagem • {{ $metrica['idade'] }}
                                            @elseif($metrica['origem'] === 'controlador_offline')
                                                Inativa • {{ $metrica['idade'] }}
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                <!-- Actions Footer -->
                @if (\App\Filament\Resources\DailyRecordResource::canCreate() || \App\Filament\Resources\OperationalActionResource::canCreate())
                    <div class="neo-pool-actions">
                        @foreach ($item['acoes_rapidas'] as $acao)
                            <a href="{{ $acao['url'] }}" class="neo-action-btn @if(!empty($acao['primary'])) neo-action-btn--primary @else neo-action-btn--outline dark:!bg-white/10 dark:!border-white/20 dark:!text-white dark:hover:!bg-white/20 @endif">
                                <x-filament::icon :icon="$acao['icon']" class="neo-icon-sm" />
                                {{ $acao['label'] }}
                            </a>
                        @endforeach
                    </div>
                @endif
                </div> <!-- End Collapsible Content -->
            </div>
        @empty
            <div class="col-span-full py-12 text-center bg-white rounded-2xl border border-gray-100 text-slate-500">
                Nenhuma piscina ativa configurada no momento.
            </div>
        @endforelse
    </div>
</x-filament-widgets::widget>
