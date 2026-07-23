<x-filament-widgets::widget>

    {{-- ================================================================
         CABEÇALHO DO PAINEL
    ================================================================ --}}
    <div class="mmc-dash-header">
        <div class="mmc-dash-header__left">
            <div class="mmc-dash-header__eyebrow">Painel de Controlo</div>
            <h1 class="mmc-dash-header__title">Estado das Piscinas</h1>
        </div>
        <div class="mmc-dash-header__timestamp">
            <span class="mmc-dash-ts-dot"></span>
            <span>Atualizado agora</span>
        </div>
    </div>

    @if ($totalPiscinas > 0)
    {{-- ================================================================
         KPI STRIP
    ================================================================ --}}
    <div class="mmc-kpi-strip">
        @unless ($isNS)
        <div class="mmc-kpi-block">
            <div class="mmc-kpi-block__label">Registos Hoje</div>
            <div class="mmc-kpi-block__row">
                <span class="mmc-kpi-block__val">{{ $registadasHoje }}</span>
                <span class="mmc-kpi-block__total">/ {{ $totalPiscinas }}</span>
                <div class="mmc-kpi-ring" title="{{ $percentagemRegisto }}%">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9155" class="mmc-kpi-ring__bg" />
                        <circle cx="18" cy="18" r="15.9155" class="mmc-kpi-ring__arc mmc-kpi-ring__arc--blue"
                                stroke-dasharray="{{ $percentagemRegisto }}, 100"
                                stroke-dashoffset="25" />
                    </svg>
                </div>
            </div>
            <div class="mmc-kpi-block__bar">
                <div class="mmc-kpi-block__bar-fill mmc-kpi-block__bar-fill--blue" style="width: {{ $percentagemRegisto }}%"></div>
            </div>
        </div>
        @endunless

        <div class="mmc-kpi-block">
            <div class="mmc-kpi-block__label">Piscinas Conformes</div>
            <div class="mmc-kpi-block__row">
                <span class="mmc-kpi-block__val {{ $conformes === $totalPiscinas ? 'mmc-kpi-block__val--green' : ($conformes === 0 ? 'mmc-kpi-block__val--red' : '') }}">{{ $conformes }}</span>
                <span class="mmc-kpi-block__total">/ {{ $totalPiscinas }}</span>
                <div class="mmc-kpi-ring" title="{{ $percentagemConforme }}%">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9155" class="mmc-kpi-ring__bg" />
                        <circle cx="18" cy="18" r="15.9155" class="mmc-kpi-ring__arc {{ $conformes === $totalPiscinas ? 'mmc-kpi-ring__arc--green' : ($conformes === 0 ? 'mmc-kpi-ring__arc--red' : 'mmc-kpi-ring__arc--amber') }}"
                                stroke-dasharray="{{ $percentagemConforme }}, 100"
                                stroke-dashoffset="25" />
                    </svg>
                </div>
            </div>
            <div class="mmc-kpi-block__bar">
                <div class="mmc-kpi-block__bar-fill {{ $conformes === $totalPiscinas ? 'mmc-kpi-block__bar-fill--green' : ($conformes === 0 ? 'mmc-kpi-block__bar-fill--red' : 'mmc-kpi-block__bar-fill--amber') }}" style="width: {{ $percentagemConforme }}%"></div>
            </div>
        </div>

        {{-- Alertas ativos --}}
        @php
            $totalAlertas = collect($piscinas)->sum(fn($item) => count(array_filter($item['parametros_conformes'] ?? [], fn($ok) => $ok === false)));
        @endphp
        <div class="mmc-kpi-block mmc-kpi-block--wide">
            <div class="mmc-kpi-block__label">Parâmetros Fora de Limite</div>
            <div class="mmc-kpi-block__row">
                <span class="mmc-kpi-block__val {{ $totalAlertas > 0 ? 'mmc-kpi-block__val--red' : 'mmc-kpi-block__val--green' }}">{{ $totalAlertas }}</span>
                <span class="mmc-kpi-block__total mmc-kpi-block__total--label">{{ $totalAlertas === 1 ? 'alerta ativo' : 'alertas ativos' }}</span>
            </div>
            <div class="mmc-kpi-block__status-pill {{ $totalAlertas > 0 ? 'mmc-kpi-block__status-pill--red' : 'mmc-kpi-block__status-pill--green' }}">
                {{ $totalAlertas > 0 ? 'Requer atenção' : 'Tudo conforme' }}
            </div>
        </div>
    </div>
    @endif

    {{-- ================================================================
         GRID DE PISCINAS
    ================================================================ --}}
    <div class="mmc-pool-grid"
         x-data="{
             allOpen: true,
             toggleAll() {
                 this.allOpen = !this.allOpen;
                 this.$dispatch('mmc-toggle-all-pools', { open: this.allOpen });
             }
         }">

        <div class="mmc-pool-grid__controls">
            <div class="mmc-pool-grid__count">{{ $totalPiscinas }} piscina{{ $totalPiscinas !== 1 ? 's' : '' }}</div>
            <button type="button" @click="toggleAll()"
                    class="mmc-toggle-all-btn"
                    x-text="allOpen ? 'Recolher todas' : 'Expandir todas'"></button>
        </div>

        @forelse ($piscinas as $item)
            @php
                $piscina     = $item['piscina'];
                $conformeMap = $item['parametros_conformes'];
                $numFora     = count(array_filter($conformeMap, fn ($ok) => $ok === false));
                $temDados    = $item['tem_dados_conformes'];
                $estado      = ! $temDados ? 'neutro' : ($numFora > 0 ? 'bad' : 'ok');
            @endphp

            <div class="mmc-pool-card mmc-pool-card--{{ $estado }}"
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

                {{-- Barra lateral de estado --}}
                <div class="mmc-pool-card__accent"></div>

                <div class="mmc-pool-card__inner">
                    {{-- Header --}}
                    <div class="mmc-pool-header" @click="toggle()" role="button" aria-expanded="open">
                        <div class="mmc-pool-header__info">
                            <div class="mmc-pool-header__icon-wrap mmc-pool-header__icon-wrap--{{ $estado }}">
                                <x-filament::icon icon="heroicon-o-swatch" class="mmc-pool-header__icon" />
                            </div>
                            <div>
                                <div class="mmc-pool-header__name">{{ $piscina->name }}</div>
                                <div class="mmc-pool-header__install">{{ $piscina->instalacao?->name ?? 'Sem Instalação' }}</div>
                            </div>
                        </div>
                        <div class="mmc-pool-header__right">
                            @if ($estado === 'ok')
                                <span class="mmc-status-badge mmc-status-badge--ok">Conforme</span>
                            @elseif ($estado === 'bad')
                                <span class="mmc-status-badge mmc-status-badge--bad">{{ $numFora }} alerta{{ $numFora > 1 ? 's' : '' }}</span>
                            @else
                                <span class="mmc-status-badge mmc-status-badge--neutral">Sem dados</span>
                            @endif
                            <button type="button" class="mmc-pool-header__chev" :class="open ? 'mmc-pool-header__chev--open' : ''">
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
                                            <path d="{{ $m['sparkline']['fill'] }}" fill="{{ $m['ok'] === false ? 'rgba(255,61,107,0.12)' : 'rgba(0,242,254,0.08)' }}"/>
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
            </div>
        @empty
            <div class="mmc-pool-empty">
                <x-filament::icon icon="heroicon-o-no-symbol" class="w-10 h-10 opacity-30 mx-auto mb-3" />
                <p>Nenhuma piscina ativa configurada.</p>
            </div>
        @endforelse
    </div>

</x-filament-widgets::widget>
