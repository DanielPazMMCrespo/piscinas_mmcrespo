<x-filament-widgets::widget>
    <!-- Main Header -->
    <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold text-slate-900 dark:text-white tracking-tight">Painel de Controlo</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1.5 font-medium">Visão global das piscinas</p>
        </div>
        @unless ($isNS)
            <a href="{{ \App\Filament\Pages\InspecaoDgs::getUrl() }}" 
               class="inline-flex items-center gap-2 px-3.5 py-2 rounded-xl bg-slate-900 hover:bg-black text-white dark:bg-slate-800 dark:hover:bg-slate-700 text-xs font-semibold shadow-sm transition-all self-start md:self-auto min-h-[40px]">
                <x-filament::icon icon="heroicon-o-shield-check" class="w-4 h-4 text-emerald-400" />
                <span>Modo Inspeção DGS</span>
            </a>
        @endunless
    </div>

    @if ($totalPiscinas > 0)
        <!-- Top KPIs -->
        <div class="neo-top-kpis">
            @unless ($isNS)
                <div class="neo-kpi-card">
                    <div>
                        <div class="text-sm font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Registos Hoje</div>
                        <div class="text-[11px] text-slate-400 dark:text-slate-500 mb-1">piscinas com registo diário feito hoje</div>
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
                    <div class="text-[11px] text-slate-400 dark:text-slate-500 mb-1">pela leitura mais recente (sonda ou registo)</div>
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
             allOpen: window.innerWidth >= 1024,
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
                     open: window.innerWidth >= 1024,
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
                        $forasLabels = collect($item['metricas4'] ?? [])
                            ->filter(fn ($m) => ($m['ok'] ?? null) === false)
                            ->pluck('label')
                            ->implode(', ');
                        $statusLabel = $forasLabels !== '' ? $forasLabels : $numFora . ' Alerta' . ($numFora > 1 ? 's' : '');
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
                        @if (! empty($item['encerramento']))
                            <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200 whitespace-nowrap border border-slate-200 dark:border-slate-700">Encerrada</span>
                        @elseif (! empty($item['sem_hoje']))
                            <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 whitespace-nowrap border border-amber-200 dark:border-amber-700/50">Falta registar</span>
                        @elseif ($estadoGeral === 'ok')
                            <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300 whitespace-nowrap border border-emerald-200 dark:border-emerald-700/50">Concluído hoje ✓</span>
                        @else
                            <span class="text-[11px] font-semibold uppercase tracking-wider {{ $statusColor }} text-right" x-show="!open" x-cloak>{{ $statusLabel }}</span>
                        @endif
                        <button type="button" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 min-h-[44px] min-w-[44px] flex items-center justify-center transition-transform duration-300 ease-out" :class="open ? 'rotate-180' : ''">
                            <x-filament::icon icon="heroicon-m-chevron-down" class="w-5 h-5" />
                        </button>
                    </div>
                </div>

                <!-- Collapsible Content -->
                <div x-show="open" x-collapse x-cloak class="flex flex-col gap-4 mt-2">
                    @if (! empty($item['encerramento']))
                        <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 px-3 py-2.5 text-xs text-slate-600 dark:text-slate-300">
                            <div class="flex items-center gap-1.5 font-semibold text-slate-700 dark:text-slate-200">
                                <x-filament::icon icon="heroicon-m-lock-closed" class="w-4 h-4" />
                                Encerrada {{ $item['encerramento']['periodo'] }}
                            </div>
                            <div class="mt-1">
                                {{ $item['encerramento']['motivo'] }} ·
                                {{ $item['encerramento']['agua_em_tratamento']
                                    ? 'água em tratamento, registos ainda possíveis'
                                    : 'piscina parada, sem registos esperados' }}
                            </div>
                            <div class="mt-1 text-slate-500 dark:text-slate-400">Os valores abaixo são os últimos conhecidos.</div>
                        </div>
                    @endif

                    <!-- Metrics Grid 2x2 -->
                    <div class="neo-metrics-grid">
                        @foreach (['ph', 'redox', 'livre', 'combinado', 'temp', 'turbidez'] as $key)
                            @php
                                $metrica = $item['metricas4'][$key];
                            @endphp
                            <div class="neo-metric-card {{ $metrica['ok'] === false ? 'neo-metric-card--alert' : '' }}"
                                 title="{{ $metrica['tooltip'] ?? $metrica['valor'] }}">
                                <div class="neo-metric-label flex items-center justify-between gap-1">
                                    <span>{{ $metrica['label'] }}</span>
                                    @if(!empty($metrica['limite_resumo']))
                                        <span class="text-[10px] font-normal tracking-tight {{ $metrica['ok'] === false ? 'text-rose-600 dark:text-rose-400 font-semibold' : 'text-slate-400 dark:text-slate-500' }}"
                                              title="{{ $metrica['tooltip'] ?? '' }}">
                                            {{ $metrica['limite_resumo'] }}
                                        </span>
                                    @endif
                                </div>
                                <div class="neo-metric-value" title="{{ $metrica['tooltip'] ?? $metrica['valor'] }}">{{ $metrica['valor'] }}</div>
                                <!-- Sparkline -->
                                @if(isset($metrica['sparkline']) && $metrica['sparkline'])
                                    <svg class="neo-metric-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none">
                                        <path d="{{ $metrica['sparkline']['fill'] }}" fill="{{ $metrica['ok'] === false ? '#ffe4e6' : '#eff6ff' }}" opacity="0.6"/>
                                        <path d="{{ $metrica['sparkline']['stroke'] }}" fill="none" stroke="{{ $metrica['ok'] === false ? '#f43f5e' : '#3b82f6' }}" stroke-width="1.5"/>
                                    </svg>
                                @else
                                    <div class="neo-metric-sparkline"></div>
                                @endif

                                <div class="neo-metric-footer">
                                    <div class="neo-metric-status {{ $metrica['ok'] === false ? 'neo-metric-status--bad' : ($metrica['ok'] === true ? 'neo-metric-status--ok' : '') }}"
                                         title="{{ $metrica['tooltip'] ?? '' }}">
                                        @if($metrica['ok'] !== null)
                                            <div class="w-2 h-2 rounded-full {{ $metrica['ok'] === false ? 'bg-rose-500' : 'bg-emerald-500' }}"></div>
                                            {{ $metrica['ok'] === false ? 'Alerta' : 'OK' }}
                                        @else
                                            <div class="w-2 h-2 rounded-full bg-slate-300"></div>
                                            N/A
                                        @endif
                                    </div>
                                    @if($metrica['origem'] !== 'sem_dados')
                                        @php
                                            $origemBase = match ($metrica['origem']) {
                                                'controlador' => 'Sonda',
                                                'manual' => 'Manual',
                                                'artefacto' => 'Lavagem',
                                                'controlador_offline' => 'Inativa',
                                                default => null,
                                            };
                                            $origemBadge = ($metrica['origem'] === 'manual' && !empty($metrica['autor_curto']))
                                                ? 'Manual (' . $metrica['autor_curto'] . ')'
                                                : $origemBase;
                                            $origemTitle = ($metrica['origem'] === 'manual' && !empty($metrica['autor']))
                                                ? 'Análise manual por ' . $metrica['autor'] . ' • ' . $metrica['idade']
                                                : ($origemBase . ' • ' . $metrica['idade']);
                                        @endphp
                                        @if($origemBadge)
                                            <div class="neo-metric-origem" title="{{ $origemTitle }}">
                                                {{ $origemBadge }} • {{ $metrica['idade'] }}
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Última análise manual e autor --}}
                    @if (! empty($item['ultimo_registo_manual']))
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span>
                            <span>
                                Análise manual por <strong class="font-medium text-slate-700 dark:text-slate-200">{{ $item['ultimo_registo_manual']['autor'] }}</strong>
                                · {{ $item['ultimo_registo_manual']['idade'] }}
                                @if(!empty($item['ultimo_registo_manual']['hora']))
                                    ({{ $item['ultimo_registo_manual']['hora'] }})
                                @endif
                            </span>
                        </div>
                    @endif

                    {{-- Estado da sonda, sempre visível: com um registo manual fresco a
                         cascata de fontes escolhe "manual" e o estado da sonda deixava
                         de aparecer em qualquer sítio a que o técnico tenha acesso. --}}
                    @if (! empty($item['sonda']['instalada']))
                        @php
                            $idadeSonda = $item['sonda']['idade_min'];
                            $avariaSonda = $item['sonda']['avaria'] ?? null;
                        @endphp
                        <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-1 flex items-center gap-1.5">
                            <span class="w-1.5 h-1.5 rounded-full {{ $avariaSonda ? 'bg-amber-500' : ($idadeSonda === null ? 'bg-rose-500' : ($idadeSonda <= 60 ? 'bg-emerald-500' : 'bg-amber-500')) }}"></span>
                            @if ($avariaSonda)
                                {{-- Causa conhecida e reportada: substitui o diagnóstico
                                     automático, que aqui só diria "verificar controlador". --}}
                                <span class="font-medium text-amber-700 dark:text-amber-400">
                                    Sonda indisponível: {{ $avariaSonda['motivo'] }}
                                </span>
                                <span>· desde {{ $avariaSonda['desde_humano'] }}</span>
                            @elseif ($idadeSonda === null)
                                Sonda instalada, sem leituras
                            @elseif ($idadeSonda < 1)
                                Sonda: leitura agora
                            @elseif ($idadeSonda < 60)
                                Sonda: leitura há {{ $idadeSonda }} min
                            @else
                                Sonda sem leituras há {{ (int) floor($idadeSonda / 60) }}h — verificar controlador
                            @endif
                        </div>
                        @if ($avariaSonda && filled($avariaSonda['detalhe']))
                            <div class="text-[11px] text-slate-400 dark:text-slate-500 pl-3">{{ $avariaSonda['detalhe'] }}</div>
                        @endif
                        @if (! empty($item['sonda']['url_reportar']))
                            <a href="{{ $item['sonda']['url_reportar'] }}" class="text-[11px] text-primary-600 dark:text-primary-400 underline pl-3">
                                {{ $avariaSonda ? 'Atualizar / dar baixa' : 'Reportar avaria da sonda' }}
                            </a>
                        @endif
                    @endif

                    {{-- Bidões de Químicos & Autonomia Preditiva (apenas Admin, Gestor e Técnico) --}}
                    @if (! $isNS && ! empty($item['bidoes']))
                        <div class="mt-2 pt-2 border-t border-slate-100 dark:border-white/5 flex flex-wrap items-center gap-2">
                            <span class="text-[11px] font-medium text-slate-500 dark:text-slate-400">Bidões:</span>
                            @foreach ($item['bidoes'] as $bidao)
                                @php
                                    $isCritico = $bidao['status'] === 'critico' || $bidao['esta_baixo'];
                                    $isAviso = $bidao['status'] === 'aviso' || $bidao['esgota_fim_de_semana'];
                                    $badgeBg = $isCritico
                                        ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-400 border-rose-200 dark:border-rose-800'
                                        : ($isAviso
                                            ? 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400 border-amber-200 dark:border-amber-800'
                                            : 'bg-slate-50 dark:bg-white/5 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-700');
                                @endphp
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-medium {{ $badgeBg }}"
                                     title="{{ $bidao['label'] }}: {{ $bidao['descricao_autonomia'] }} (Nível: {{ $bidao['percentagem'] !== null ? $bidao['percentagem'].'%' : '—' }})">
                                    <x-filament::icon icon="heroicon-m-beaker" class="w-3.5 h-3.5" />
                                    <span><strong>{{ $bidao['label'] }}:</strong> {{ $bidao['percentagem'] !== null ? $bidao['percentagem'].'%' : '—' }}</span>
                                    <span class="text-[10px] opacity-75">({{ $bidao['descricao_autonomia'] }})</span>
                                    @if ($bidao['esgota_fim_de_semana'])
                                        <span class="px-1 py-0.5 text-[9px] font-bold bg-amber-200 dark:bg-amber-800 text-amber-900 dark:text-white rounded uppercase">Fim de Semana</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                <!-- Actions Footer -->
                @if (\App\Filament\Resources\DailyRecordResource::canCreate() || \App\Filament\Resources\OperationalActionResource::canCreate())
                    <div class="neo-pool-actions">
                        @foreach ($item['acoes_rapidas'] as $acao)
                            <a href="{{ $acao['url'] }}" class="neo-action-btn {{ !empty($acao['primary']) ? 'neo-action-btn--primary' : 'neo-action-btn--outline' }}">
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
