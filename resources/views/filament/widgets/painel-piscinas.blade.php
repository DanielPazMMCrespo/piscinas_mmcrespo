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

    <style>
        :root {
            --mmc-card-border: #e5e7eb;
            --mmc-card-bg: #ffffff;
            --mmc-cta-bg: #f2f9fd;
            --mmc-cta-border: #d3e9f6;
            --mmc-cta-color: #1573a8;
            --mmc-cta-hover-bg: #e2f1fa;
            --mmc-bad-color: #dc2626;
            --mmc-ok-color: #16a34a;
            --mmc-metric-label-opacity: 0.72;
            --mmc-progress-bg: #e5e7eb;
            --mmc-divider-color: #e5e7eb;
            --mmc-controlador-tag-color: #1573a8;
            --mmc-controlador-tag-bg: #e8f4fd;
            --mmc-registo-tag-color: #6b7280;
            --mmc-registo-tag-bg: #f3f4f6;
        }

        .dark {
            --mmc-card-border: #3f3f46;
            --mmc-card-bg: rgba(255, 255, 255, 0.02);
            --mmc-cta-bg: rgba(43, 156, 216, 0.12);
            --mmc-cta-border: rgba(43, 156, 216, 0.3);
            --mmc-cta-color: #7cc4e8;
            --mmc-cta-hover-bg: rgba(43, 156, 216, 0.2);
            --mmc-bad-color: #f87171;
            --mmc-ok-color: #4ade80;
            --mmc-metric-label-opacity: 0.8;
            --mmc-progress-bg: #3f3f46;
            --mmc-divider-color: #3f3f46;
            --mmc-controlador-tag-color: #7cc4e8;
            --mmc-controlador-tag-bg: rgba(43, 156, 216, 0.15);
            --mmc-registo-tag-color: #9ca3af;
            --mmc-registo-tag-bg: rgba(255, 255, 255, 0.06);
        }

        /* Progress Bar Styles */
        .mmc-dashboard-status {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1.25rem;
            padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--mmc-card-border);
        }
        @media (min-width: 640px) {
            .mmc-dashboard-status {
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
            }
        }
        .mmc-status-item {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .mmc-status-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
        }
        .mmc-status-title {
            font-weight: 600;
            opacity: 0.85;
        }
        .mmc-status-value {
            font-weight: 700;
            color: var(--mmc-cta-color);
        }
        .mmc-progress-bar-bg {
            height: 0.5rem;
            background: var(--mmc-progress-bg);
            border-radius: 9999px;
            overflow: hidden;
            width: 100%;
        }
        .mmc-progress-bar-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.4s ease-in-out;
        }
        .mmc-progress-registo {
            background: linear-gradient(90deg, #2b9cd8, #1573a8);
        }
        .mmc-progress-conforme {
            background: linear-gradient(90deg, #76b82a, #5c911e);
        }

        /* General styles */
        .mmc-piscinas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr));
            gap: 0.75rem;
        }
        .mmc-card {
            border: 1px solid var(--mmc-card-border);
            background: var(--mmc-card-bg);
            border-radius: 0.75rem;
            padding: 0.875rem 1rem;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .mmc-card-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
        }
        .mmc-pool-name { font-weight: 700; font-size: 0.95rem; }
        .mmc-pool-inst { font-size: 0.75rem; opacity: 0.6; }

        /* Divisor de secção (Controlador / Registo Manual) */
        .mmc-section-divider {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.6rem;
            margin-bottom: 0.4rem;
        }
        .mmc-controlador-tag {
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--mmc-controlador-tag-color);
            background: var(--mmc-controlador-tag-bg);
            padding: 0.1rem 0.4rem;
            border-radius: 0.25rem;
            white-space: nowrap;
        }
        .mmc-controlador-tag--stale {
            color: #d97706;
            background: rgba(217, 119, 6, 0.1);
        }
        .mmc-controlador-age {
            font-size: 0.68rem;
            opacity: 0.5;
            margin-left: auto;
        }
        .mmc-controlador-age--stale {
            color: #d97706;
            opacity: 1;
            font-weight: 600;
        }
        .mmc-registo-tag {
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--mmc-registo-tag-color);
            background: var(--mmc-registo-tag-bg);
            padding: 0.1rem 0.4rem;
            border-radius: 0.25rem;
            white-space: nowrap;
        }
        .mmc-card-time {
            font-size: 0.68rem;
            opacity: 0.55;
            margin-left: auto;
        }
        .mmc-card-time.mmc-warn { color: #d97706; opacity: 1; font-weight: 600; }

        .mmc-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.4rem 0.75rem;
        }
        .mmc-metric { display: flex; flex-direction: column; }
        .mmc-metric-label { font-size: 0.7rem; opacity: var(--mmc-metric-label-opacity); }
        .mmc-metric-value { font-size: 1.05rem; font-weight: 700; line-height: 1.2; }

        /* Valores do registo manual: conformes em tom neutro (anti alarm-fatigue). */
        .mmc-ok { color: inherit; opacity: 0.85; }
        /* Valores do controlador: conformes a verde real (é em tempo real, interessa ver). */
        .mmc-sensor-ok { color: var(--mmc-ok-color); }
        .mmc-na { color: inherit; opacity: 0.35; }
        .mmc-bad { color: var(--mmc-bad-color); }
        .mmc-empty { opacity: 0.5; font-size: 0.85rem; padding: 0.3rem 0; }

        /* CTA por piscina */
        .mmc-card-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            min-height: 40px;
            margin-top: 0.75rem;
            border: 1px solid var(--mmc-cta-border);
            border-radius: 0.5rem;
            background: var(--mmc-cta-bg);
            color: var(--mmc-cta-color);
            font-size: 0.8rem;
            font-weight: 600;
            text-decoration: none;
            transition: background 0.15s ease;
        }
        .mmc-card-cta:hover { background: var(--mmc-cta-hover-bg); }
        .mmc-card-cta-icon { width: 1rem; height: 1rem; }
    </style>
</x-filament-widgets::widget>
