<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Estado das Piscinas</x-slot>
        <x-slot name="description">Último registo válido de cada piscina. Vermelho = fora dos limites CN 14/DA.</x-slot>
        <x-slot name="headerEnd">
            <x-filament::button tag="a" href="{{ $urlRegistar }}" icon="heroicon-m-plus-circle" size="sm">
                Registar agora
            </x-filament::button>
        </x-slot>

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

        <div class="mmc-piscinas-grid">
            @forelse ($piscinas as $item)
                <div class="mmc-card">
                    <div class="mmc-card-head">
                        <span class="mmc-pool-name">{{ $item['piscina']->name }}</span>
                        <span class="mmc-pool-inst">{{ $item['piscina']->instalacao?->name }}</span>
                    </div>

                    {{-- Controlador Hanna (BL132): leitura automática em tempo real. --}}
                    @if ($item['controlador'])
                        <div class="mmc-section-divider">
                            <span class="mmc-controlador-tag {{ $item['controlador']['stale'] ? 'mmc-controlador-tag--stale' : '' }}">Controlador</span>
                            <span class="mmc-controlador-age {{ $item['controlador']['stale'] ? 'mmc-controlador-age--stale' : '' }}">{{ $item['controlador']['idade_txt'] }}</span>
                        </div>
                        <div class="mmc-metrics">
                            @if ($item['controlador']['ph'] !== null)
                                <div class="mmc-metric">
                                    <span class="mmc-metric-label">pH</span>
                                    <span class="mmc-metric-value {{ $item['controlador']['ph_ok'] === null ? 'mmc-na' : ($item['controlador']['ph_ok'] ? 'mmc-sensor-ok' : 'mmc-bad') }}">{{ $item['controlador']['ph'] }}</span>
                                </div>
                            @endif
                            @if ($item['controlador']['orp'] !== null)
                                <div class="mmc-metric">
                                    <span class="mmc-metric-label">ORP</span>
                                    <span class="mmc-metric-value {{ $item['controlador']['orp_ok'] === null ? 'mmc-na' : ($item['controlador']['orp_ok'] ? 'mmc-sensor-ok' : 'mmc-bad') }}">{{ $item['controlador']['orp'] }} mV</span>
                                </div>
                            @endif
                            @if ($item['controlador']['temp'] !== null)
                                <div class="mmc-metric">
                                    <span class="mmc-metric-label">Temp. Água</span>
                                    <span class="mmc-metric-value {{ $item['controlador']['temp_ok'] === null ? 'mmc-na' : ($item['controlador']['temp_ok'] ? 'mmc-sensor-ok' : 'mmc-bad') }}">{{ $item['controlador']['temp'] }} °C</span>
                                </div>
                            @endif
                        </div>
                    @endif

                    @if ($item['registo'])
                        <div class="mmc-section-divider">
                            <span class="mmc-registo-tag">Registo Manual</span>
                            <span class="mmc-card-time {{ $item['sem_hoje'] ? 'mmc-warn' : '' }}">
                                @if ($item['sem_hoje'])
                                    ⚠ último em {{ $item['registo']->registado_em->format('d/m H:i') }}
                                @else
                                    {{ $item['registo']->registado_em->format('H:i') }} · {{ $item['ha_quanto'] }}
                                @endif
                            </span>
                        </div>

                        <div class="mmc-metrics">
                            @foreach ($item['metricas'] as $m)
                                <div class="mmc-metric">
                                    <span class="mmc-metric-label">{{ $m['label'] }}</span>
                                    <span class="mmc-metric-value {{ $m['ok'] === null ? 'mmc-na' : ($m['ok'] ? 'mmc-ok' : 'mmc-bad') }}">{{ $m['valor'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @elseif (!$item['controlador'])
                        <div class="mmc-empty">Sem registos</div>
                    @else
                        <div class="mmc-section-divider">
                            <span class="mmc-registo-tag">Registo Manual</span>
                        </div>
                        <div class="mmc-empty">Sem registos manuais</div>
                    @endif

                    <a href="{{ $item['url_registar'] }}" class="mmc-card-cta">
                        <x-filament::icon icon="heroicon-m-pencil-square" class="mmc-card-cta-icon" />
                        Registar
                    </a>
                </div>
            @empty
                <div class="mmc-empty">Nenhuma piscina ativa.</div>
            @endforelse
        </div>
    </x-filament::section>

</x-filament-widgets::widget>
