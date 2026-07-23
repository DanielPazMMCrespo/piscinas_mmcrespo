<x-filament-widgets::widget>

    @php
        // ---- Resumo operacional global (só apresentação; não altera dados) ----
        $totalAlertas = collect($piscinas)->sum(fn ($item) =>
            count(array_filter($item['parametros_conformes'] ?? [], fn ($ok) => $ok === false))
        );
        $semDados = collect($piscinas)->filter(fn ($item) => ! ($item['tem_dados_conformes'] ?? false))->count();

        if ($totalPiscinas > 0 && $totalAlertas === 0 && $semDados === 0) {
            $estadoGlobal = 'ok';
            $estadoGlobalTxt = 'Operação estável';
        } elseif ($totalAlertas > 0) {
            $estadoGlobal = 'bad';
            $estadoGlobalTxt = 'Intervenção necessária';
        } else {
            $estadoGlobal = 'warn';
            $estadoGlobalTxt = 'A aguardar leituras';
        }
    @endphp

    {{-- ================================================================
         CABEÇALHO EXECUTIVO
    ================================================================ --}}
    <header class="mmc-cmd-header mmc-cmd-header--{{ $estadoGlobal }}">
        <div class="mmc-cmd-header__main">
            <div class="mmc-cmd-header__eyebrow">
                <span class="mmc-cmd-mark">MMC</span>
                <span>Centro de Operações</span>
            </div>
            <h1 class="mmc-cmd-header__title">Monitorização de Piscinas</h1>
            <p class="mmc-cmd-header__subtitle">
                Estado da qualidade da água e conformidade em tempo real
            </p>
        </div>

        <div class="mmc-cmd-header__status">
            <div class="mmc-cmd-status-chip mmc-cmd-status-chip--{{ $estadoGlobal }}">
                <span class="mmc-cmd-status-dot"></span>
                {{ $estadoGlobalTxt }}
            </div>
            <div class="mmc-cmd-header__clock">
                <x-filament::icon icon="heroicon-m-clock" class="mmc-cmd-clock-icon" />
                <span>{{ now()->format('d/m/Y · H:i') }}</span>
            </div>
        </div>
    </header>

    @if ($totalPiscinas > 0)
    {{-- ================================================================
         FAIXA DE INDICADORES
    ================================================================ --}}
    <section class="mmc-kpi-strip" aria-label="Indicadores operacionais">

        @unless ($isNS)
        <article class="mmc-kpi-block">
            <div class="mmc-kpi-block__head">
                <span class="mmc-kpi-block__label">Registos hoje</span>
                <div class="mmc-kpi-ring" title="{{ $percentagemRegisto }}%" aria-hidden="true">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9155" class="mmc-kpi-ring__bg" />
                        <circle cx="18" cy="18" r="15.9155" class="mmc-kpi-ring__arc mmc-kpi-ring__arc--blue"
                                stroke-dasharray="{{ $percentagemRegisto }}, 100" stroke-dashoffset="25" />
                    </svg>
                    <span class="mmc-kpi-ring__pct">{{ $percentagemRegisto }}%</span>
                </div>
            </div>
            <div class="mmc-kpi-block__row">
                <span class="mmc-kpi-block__val">{{ $registadasHoje }}</span>
                <span class="mmc-kpi-block__total">de {{ $totalPiscinas }}</span>
            </div>
            <div class="mmc-kpi-block__foot">
                <div class="mmc-kpi-block__bar">
                    <div class="mmc-kpi-block__bar-fill mmc-kpi-block__bar-fill--blue" style="width: {{ $percentagemRegisto }}%"></div>
                </div>
                <span class="mmc-kpi-block__hint">{{ $totalPiscinas - $registadasHoje }} por registar</span>
            </div>
        </article>
        @endunless

        <article class="mmc-kpi-block">
            <div class="mmc-kpi-block__head">
                <span class="mmc-kpi-block__label">Conformidade</span>
                <div class="mmc-kpi-ring" title="{{ $percentagemConforme }}%" aria-hidden="true">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9155" class="mmc-kpi-ring__bg" />
                        <circle cx="18" cy="18" r="15.9155"
                                class="mmc-kpi-ring__arc {{ $conformes === $totalPiscinas ? 'mmc-kpi-ring__arc--green' : ($conformes === 0 ? 'mmc-kpi-ring__arc--red' : 'mmc-kpi-ring__arc--amber') }}"
                                stroke-dasharray="{{ $percentagemConforme }}, 100" stroke-dashoffset="25" />
                    </svg>
                    <span class="mmc-kpi-ring__pct">{{ $percentagemConforme }}%</span>
                </div>
            </div>
            <div class="mmc-kpi-block__row">
                <span class="mmc-kpi-block__val {{ $conformes === $totalPiscinas ? 'mmc-kpi-block__val--green' : ($conformes === 0 ? 'mmc-kpi-block__val--red' : '') }}">{{ $conformes }}</span>
                <span class="mmc-kpi-block__total">de {{ $totalPiscinas }} conformes</span>
            </div>
            <div class="mmc-kpi-block__foot">
                <div class="mmc-kpi-block__bar">
                    <div class="mmc-kpi-block__bar-fill {{ $conformes === $totalPiscinas ? 'mmc-kpi-block__bar-fill--green' : ($conformes === 0 ? 'mmc-kpi-block__bar-fill--red' : 'mmc-kpi-block__bar-fill--amber') }}" style="width: {{ $percentagemConforme }}%"></div>
                </div>
                <span class="mmc-kpi-block__hint">{{ $totalPiscinas - $conformes }} fora de conformidade</span>
            </div>
        </article>

        {{-- Alertas ativos --}}
        <article class="mmc-kpi-block mmc-kpi-block--focus mmc-kpi-block--{{ $totalAlertas > 0 ? 'alert' : 'clear' }}">
            <div class="mmc-kpi-block__head">
                <span class="mmc-kpi-block__label">Parâmetros fora de limite</span>
                <div class="mmc-kpi-block__icon {{ $totalAlertas > 0 ? 'mmc-kpi-block__icon--red' : 'mmc-kpi-block__icon--green' }}">
                    <x-filament::icon :icon="$totalAlertas > 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-shield-check'" class="mmc-kpi-block__icon-svg" />
                </div>
            </div>
            <div class="mmc-kpi-block__row">
                <span class="mmc-kpi-block__val {{ $totalAlertas > 0 ? 'mmc-kpi-block__val--red' : 'mmc-kpi-block__val--green' }}">{{ $totalAlertas }}</span>
                <span class="mmc-kpi-block__total">{{ $totalAlertas === 1 ? 'alerta ativo' : 'alertas ativos' }}</span>
            </div>
            <div class="mmc-kpi-block__foot">
                <span class="mmc-kpi-block__status-pill {{ $totalAlertas > 0 ? 'mmc-kpi-block__status-pill--red' : 'mmc-kpi-block__status-pill--green' }}">
                    {{ $totalAlertas > 0 ? 'Requer atenção imediata' : 'Todos os limites cumpridos' }}
                </span>
            </div>
        </article>
    </section>
    @endif

    {{-- ================================================================
         GRELHA DE ESTAÇÕES (PISCINAS)
    ================================================================ --}}
    <section class="mmc-pool-grid"
         x-data="{
             allOpen: true,
             toggleAll() {
                 this.allOpen = !this.allOpen;
                 this.$dispatch('mmc-toggle-all-pools', { open: this.allOpen });
             }
         }">

        <div class="mmc-pool-grid__controls">
            <div class="mmc-pool-grid__count">
                <x-filament::icon icon="heroicon-m-squares-2x2" class="mmc-pool-grid__count-icon" />
                {{ $totalPiscinas }} {{ $totalPiscinas !== 1 ? 'estações monitorizadas' : 'estação monitorizada' }}
            </div>
            <button type="button" @click="toggleAll()" class="mmc-toggle-all-btn">
                <x-filament::icon x-show="allOpen" icon="heroicon-m-arrows-pointing-in" class="mmc-toggle-all-icon" />
                <x-filament::icon x-show="!allOpen" x-cloak icon="heroicon-m-arrows-pointing-out" class="mmc-toggle-all-icon" />
                <span x-text="allOpen ? 'Recolher todas' : 'Expandir todas'"></span>
            </button>
        </div>

        @forelse ($piscinas as $item)
            @php
                $piscina     = $item['piscina'];
                $conformeMap = $item['parametros_conformes'];
                $numFora     = count(array_filter($conformeMap, fn ($ok) => $ok === false));
                $temDados    = $item['tem_dados_conformes'];
                $estado      = ! $temDados ? 'neutro' : ($numFora > 0 ? 'bad' : 'ok');
            @endphp

            <article class="mmc-pool-card mmc-pool-card--{{ $estado }}"
                 wire:key="pool-card-{{ $piscina->id }}"
                 x-data="{
                     open: true,
                     init() {
                         try {
                             const s = localStorage.getItem('neo_pool_open_{{ $piscina->id }}');
                             if (s !== null) this.open = s === 'true';
                         } catch (e) {}
                     },
                     toggle() {
                         this.open = !this.open;
                         try { localStorage.setItem('neo_pool_open_{{ $piscina->id }}', this.open); } catch (e) {}
                     }
                 }"
                 @mmc-toggle-all-pools.window="
                     open = $event.detail.open;
                     try { localStorage.setItem('neo_pool_open_{{ $piscina->id }}', open); } catch(e) {}
                 ">

                {{-- Barra de estado --}}
                <div class="mmc-pool-card__accent"></div>

                <div class="mmc-pool-card__inner">
                    {{-- Header --}}
                    <div class="mmc-pool-header" @click="toggle()" role="button" tabindex="0"
                         @keydown.enter="toggle()" @keydown.space.prevent="toggle()" :aria-expanded="open">
                        <div class="mmc-pool-header__info">
                            <div class="mmc-pool-header__icon-wrap mmc-pool-header__icon-wrap--{{ $estado }}">
                                <x-filament::icon icon="heroicon-o-beaker" class="mmc-pool-header__icon" />
                            </div>
                            <div class="mmc-pool-header__meta">
                                <div class="mmc-pool-header__name">{{ $piscina->name }}</div>
                                <div class="mmc-pool-header__install">
                                    <x-filament::icon icon="heroicon-m-map-pin" class="mmc-pool-header__install-icon" />
                                    {{ $piscina->instalacao?->name ?? 'Sem instalação' }}
                                </div>
                            </div>
                        </div>
                        <div class="mmc-pool-header__right">
                            @if ($estado === 'ok')
                                <span class="mmc-status-badge mmc-status-badge--ok">
                                    <span class="mmc-status-badge__dot"></span>Conforme
                                </span>
                            @elseif ($estado === 'bad')
                                <span class="mmc-status-badge mmc-status-badge--bad">
                                    <span class="mmc-status-badge__dot"></span>{{ $numFora }} alerta{{ $numFora > 1 ? 's' : '' }}
                                </span>
                            @else
                                <span class="mmc-status-badge mmc-status-badge--neutral">Sem dados</span>
                            @endif
                            <button type="button" class="mmc-pool-header__chev" :class="open ? 'mmc-pool-header__chev--open' : ''" tabindex="-1" aria-hidden="true">
                                <x-filament::icon icon="heroicon-m-chevron-down" class="w-5 h-5" />
                            </button>
                        </div>
                    </div>

                    {{-- Collapsible body --}}
                    <div x-show="open" x-collapse x-cloak class="mmc-pool-body">

                        {{-- Métricas --}}
                        <div class="mmc-metrics-grid">
                            @foreach (['ph', 'redox', 'livre', 'combinado', 'temp', 'turbidez'] as $key)
                                @php($m = $item['metricas4'][$key])
                                <div class="mmc-metric {{ $m['ok'] === false ? 'mmc-metric--alert' : ($m['ok'] === true ? 'mmc-metric--ok' : '') }}">
                                    <div class="mmc-metric__top">
                                        <span class="mmc-metric__label">{{ $m['label'] }}</span>
                                        <span class="mmc-metric__dot
                                            {{ $m['ok'] === false ? 'mmc-metric__dot--bad' : ($m['ok'] === true ? 'mmc-metric__dot--ok' : 'mmc-metric__dot--na') }}"></span>
                                    </div>
                                    <div class="mmc-metric__value {{ $m['ok'] === false ? 'mmc-metric__value--bad' : '' }}">{{ $m['valor'] }}</div>

                                    @if(isset($m['sparkline']) && $m['sparkline'])
                                        <svg class="mmc-metric__sparkline" viewBox="0 0 100 28" preserveAspectRatio="none">
                                            <path d="{{ $m['sparkline']['fill'] }}" fill="{{ $m['ok'] === false ? 'rgba(255,61,107,0.14)' : 'rgba(0,242,254,0.10)' }}"/>
                                            <path d="{{ $m['sparkline']['stroke'] }}" fill="none"
                                                  stroke="{{ $m['ok'] === false ? '#FF3D6B' : '#00F2FE' }}"
                                                  stroke-width="1.5" stroke-linecap="round"/>
                                        </svg>
                                    @else
                                        <div class="mmc-metric__sparkline mmc-metric__sparkline--empty"></div>
                                    @endif

                                    <div class="mmc-metric__footer">
                                        @if($m['ok'] === false)
                                            <span class="mmc-metric__badge mmc-metric__badge--bad">Alerta</span>
                                        @elseif($m['ok'] === true)
                                            <span class="mmc-metric__badge mmc-metric__badge--ok">OK</span>
                                        @else
                                            <span class="mmc-metric__badge mmc-metric__badge--na">N/A</span>
                                        @endif
                                        @if($m['origem'] !== 'sem_dados')
                                            <span class="mmc-metric__origin">
                                                @if($m['origem'] === 'controlador') Sonda
                                                @elseif($m['origem'] === 'manual') Manual
                                                @elseif($m['origem'] === 'artefacto') Lavagem
                                                @else Inativa
                                                @endif
                                                &middot; {{ $m['idade'] }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Ações --}}
                        @if (\App\Filament\Resources\DailyRecordResource::canCreate() || \App\Filament\Resources\OperationalActionResource::canCreate())
                            <div class="mmc-pool-actions">
                                @foreach ($item['acoes_rapidas'] as $acao)
                                    <a href="{{ $acao['url'] }}"
                                       class="mmc-action {{ !empty($acao['primary']) ? 'mmc-action--primary' : 'mmc-action--ghost' }}">
                                        <x-filament::icon :icon="$acao['icon']" class="w-3.5 h-3.5" />
                                        {{ $acao['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        @endif

                    </div>{{-- /mmc-pool-body --}}
                </div>{{-- /mmc-pool-card__inner --}}
            </article>
        @empty
            <div class="mmc-pool-empty">
                <div class="mmc-pool-empty__icon">
                    <x-filament::icon icon="heroicon-o-circle-stack" class="w-8 h-8" />
                </div>
                <p class="mmc-pool-empty__title">Nenhuma piscina ativa configurada</p>
                <p class="mmc-pool-empty__sub">As estações de monitorização aparecerão aqui assim que forem ativadas.</p>
            </div>
        @endforelse
    </section>

</x-filament-widgets::widget>
