<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Estado das Piscinas</x-slot>
        <x-slot name="description">Vermelho = fora dos limites CN 14/DA (leitura do controlador).</x-slot>
        @can('create', \App\Models\DailyRecord::class)
            <x-slot name="headerEnd">
                <x-filament::button tag="a" href="{{ $urlRegistar }}" icon="heroicon-m-plus-circle" size="sm">
                    Registar agora
                </x-filament::button>
            </x-slot>
        @endcan

        @if ($totalPiscinas > 0)
            <div class="mmc-dashboard-status">
                <div class="mmc-status-item">
                    <div class="mmc-status-info">
                        <span class="mmc-status-title">Registos Diários de Hoje</span>
                        <span class="mmc-status-value">{{ $registadasHoje }} / {{ $totalPiscinas }}</span>
                    </div>
                    <div class="mmc-progress-bar-bg">
                        <div class="mmc-progress-bar-fill mmc-progress-registo" style="width: {{ $percentagemRegisto }}%"></div>
                    </div>
                </div>
                <div class="mmc-status-item">
                    <div class="mmc-status-info">
                        <span class="mmc-status-title">Piscinas Conformes (Limites Legais)</span>
                        <span class="mmc-status-value">{{ $conformes }} / {{ $totalPiscinas }}</span>
                    </div>
                    <div class="mmc-progress-bar-bg">
                        <div class="mmc-progress-bar-fill mmc-progress-conforme" style="width: {{ $percentagemConforme }}%"></div>
                    </div>
                </div>
            </div>
        @endif

        <div x-data="{ allOpen: false }">
            <div class="mmc-pool-section-head">
                <span class="mmc-pool-section-label">Piscinas</span>
                <button
                    type="button"
                    class="mmc-pool-expand-btn"
                    x-on:click="allOpen = !allOpen; $dispatch('mmc-toggle-all-pools', { open: allOpen })"
                    x-text="allOpen ? 'Recolher tudo' : 'Expandir tudo'"
                ></button>
            </div>

            <div class="mmc-pool-list">
                @php
                    $ultimaInstalacaoId = null;
                @endphp
                @forelse ($piscinas as $item)
                    @php
                        $piscina = $item['piscina'];
                        $conformes = $item['parametros_conformes'];
                        $numFora = count(array_filter($conformes, fn ($ok) => $ok === false));
                        $temDados = $item['tem_dados_conformes'];
                        $estadoDot = ! $temDados ? 'neutro' : ($numFora > 0 ? 'bad' : 'ok');
                    @endphp

                    @if ($piscina->installation_id !== $ultimaInstalacaoId)
                        @php
                            $ultimaInstalacaoId = $piscina->installation_id;
                        @endphp
                        <div class="mmc-pool-group-label">{{ $piscina->instalacao?->name }}</div>
                    @endif

                    <div
                        class="mmc-pool-row-wrap @if ($estadoDot === 'bad') mmc-pool-row-wrap--alert @endif"
                        x-data="{ open: false }"
                        x-on:mmc-toggle-all-pools.window="open = $event.detail.open"
                        wire:key="pool-row-{{ $piscina->id }}"
                    >
                        <div class="mmc-pool-row-head" x-on:click="open = !open">
                            <span class="mmc-pool-dot mmc-pool-dot--{{ $estadoDot }}"></span>
                            <span class="mmc-pool-name">{{ $piscina->name }}</span>
                            <span class="mmc-pool-badge @if ($estadoDot === 'bad') mmc-pool-badge--bad @endif">
                                @if ($estadoDot === 'neutro')
                                    sem dados
                                @elseif ($numFora > 0)
                                    {{ $numFora }} fora
                                @else
                                    conforme
                                @endif
                            </span>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="mmc-pool-chev"
                                x-bind:class="{ 'mmc-pool-chev--open': open }"
                            />
                        </div>

                        <div class="mmc-pool-detail" x-show="open" x-cloak>
                            @if ($item['controlador'])
                                <div class="mmc-pool-source-info">
                                    @if ($item['controlador']['origem'] === 'controlador')
                                        Controlador ({{ $item['controlador']['atualizado_ha'] }})
                                    @elseif ($item['controlador']['origem'] === 'manual')
                                        Registo manual ({{ $item['controlador']['atualizado_ha'] }})
                                    @else
                                        Controlador offline ({{ $item['controlador']['atualizado_ha'] }})
                                    @endif
                                </div>
                                <div class="mmc-pool-metric">
                                    <span class="mmc-pool-metric-label">pH</span>
                                    <span class="mmc-pool-metric-value @if ($item['controlador']['ph_ok'] === false) mmc-pool-metric-value--bad @elseif ($item['controlador']['ph_ok'] === true) mmc-pool-metric-value--ok @endif">
                                        {{ $item['controlador']['ph'] ?? '—' }}
                                    </span>
                                </div>
                                <div class="mmc-pool-metric">
                                    <span class="mmc-pool-metric-label">{{ $item['controlador']['middle_label'] }}</span>
                                    <span class="mmc-pool-metric-value @if ($item['controlador']['middle_ok'] === false) mmc-pool-metric-value--bad @elseif ($item['controlador']['middle_ok'] === true) mmc-pool-metric-value--ok @endif">
                                        {{ $item['controlador']['middle_value'] ?? '—' }}
                                    </span>
                                </div>
                                <div class="mmc-pool-metric">
                                    <span class="mmc-pool-metric-label">Temp.</span>
                                    <span class="mmc-pool-metric-value @if ($item['controlador']['temp_ok'] === false) mmc-pool-metric-value--bad @elseif ($item['controlador']['temp_ok'] === true) mmc-pool-metric-value--ok @endif">
                                        {{ $item['controlador']['temp'] ?? '—' }}
                                    </span>
                                </div>
                            @else
                                <div class="mmc-pool-detail-empty">Sem dados recentes.</div>
                            @endif

                            @can('create', \App\Models\DailyRecord::class)
                                <a href="{{ $item['url_registar'] }}" class="mmc-pool-detail-cta">
                                    <x-filament::icon icon="heroicon-m-pencil-square" class="mmc-pool-detail-cta-icon" />
                                    Registar
                                </a>
                            @endcan
                        </div>
                    </div>
                @empty
                    <div class="mmc-pool-detail-empty" style="padding: 0.6rem 0.4rem;">Nenhuma piscina ativa.</div>
                @endforelse
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
