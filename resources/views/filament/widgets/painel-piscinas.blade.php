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

                    @if ($item['registo'])
                        <div class="mmc-card-time {{ $item['sem_hoje'] ? 'mmc-warn' : '' }}">
                            @if ($item['sem_hoje'])
                                ⚠ Sem registo hoje — último em {{ $item['registo']->registado_em->format('d/m H:i') }}
                            @else
                                Registado às {{ $item['registo']->registado_em->format('H:i') }} · {{ $item['ha_quanto'] }}
                            @endif
                        </div>

                        <div class="mmc-metrics">
                            @foreach ($item['metricas'] as $m)
                                <div class="mmc-metric">
                                    <span class="mmc-metric-label">{{ $m['label'] }}</span>
                                    <span class="mmc-metric-value {{ $m['ok'] === null ? 'mmc-na' : ($m['ok'] ? 'mmc-ok' : 'mmc-bad') }}">{{ $m['valor'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mmc-empty">Sem registos</div>
                    @endif

                    {{-- Sonda Hanna (BL132): leitura automática, complementa a análise manual. --}}
                    @if ($item['sonda'])
                        <div class="mmc-sonda {{ $item['sonda']['stale'] ? 'mmc-sonda--stale' : '' }}">
                            <span class="mmc-sonda-tag">Sonda</span>
                            @if ($item['sonda']['ph'] !== null)
                                <span class="mmc-sonda-val {{ $item['sonda']['ph_ok'] === false ? 'mmc-bad' : '' }}">pH {{ $item['sonda']['ph'] }}</span>
                            @endif
                            @if ($item['sonda']['orp'] !== null)
                                <span class="mmc-sonda-val">{{ $item['sonda']['orp'] }} mV</span>
                            @endif
                            @if ($item['sonda']['temp'] !== null)
                                <span class="mmc-sonda-val">{{ $item['sonda']['temp'] }} °C</span>
                            @endif
                            <span class="mmc-sonda-age">{{ $item['sonda']['idade_txt'] }}</span>
                        </div>
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
            --mmc-sonda-border: #e5e7eb;
            --mmc-cta-bg: #f2f9fd;
            --mmc-cta-border: #d3e9f6;
            --mmc-cta-color: #1573a8;
            --mmc-cta-hover-bg: #e2f1fa;
            --mmc-bad-color: #dc2626;
            --mmc-metric-label-opacity: 0.72;
            --mmc-progress-bg: #e5e7eb;
        }

        .dark {
            --mmc-card-border: #3f3f46;
            --mmc-card-bg: rgba(255, 255, 255, 0.02);
            --mmc-sonda-border: #3f3f46;
            --mmc-cta-bg: rgba(43, 156, 216, 0.12);
            --mmc-cta-border: rgba(43, 156, 216, 0.3);
            --mmc-cta-color: #7cc4e8;
            --mmc-cta-hover-bg: rgba(43, 156, 216, 0.2);
            --mmc-bad-color: #f87171;
            --mmc-metric-label-opacity: 0.8;
            --mmc-progress-bg: #3f3f46;
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
            /* min(100%, 240px) garante que em telemóvel estreito o cartão ocupa
               a largura toda em vez de transbordar. */
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
        }
        .mmc-pool-name { font-weight: 700; font-size: 0.95rem; }
        .mmc-pool-inst { font-size: 0.75rem; opacity: 0.6; }
        .mmc-card-time { font-size: 0.72rem; opacity: 0.6; margin-top: 0.15rem; }
        .mmc-card-time.mmc-warn { color: #d97706; opacity: 1; font-weight: 600; }
        .mmc-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.5rem 0.75rem;
            margin-top: 0.75rem;
        }
        .mmc-metric { display: flex; flex-direction: column; }
        .mmc-metric-label { font-size: 0.7rem; opacity: var(--mmc-metric-label-opacity); }
        .mmc-metric-value { font-size: 1.05rem; font-weight: 700; line-height: 1.2; }
        /* Disciplina de cor anti alarm-fatigue: valores conformes em tom neutro
           (não saltam à vista); só o que está fora de limite fica a vermelho. */
        .mmc-ok { color: inherit; opacity: 0.85; }
        .mmc-na { color: inherit; opacity: 0.35; }
        .mmc-bad { color: var(--mmc-bad-color); }
        .mmc-empty { opacity: 0.5; font-size: 0.85rem; padding: 0.5rem 0; }

        /* Linha da sonda: discreta, separada da análise manual. */
        .mmc-sonda {
            display: flex;
            align-items: baseline;
            gap: 0.6rem;
            flex-wrap: wrap;
            margin-top: 0.75rem;
            padding-top: 0.6rem;
            border-top: 1px dashed var(--mmc-sonda-border);
            font-size: 0.74rem;
        }
        .mmc-sonda-tag {
            font-size: 0.62rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #2b9cd8;
        }
        .mmc-sonda-val { font-weight: 600; opacity: 0.85; }
        .mmc-sonda-age { margin-left: auto; opacity: 0.5; font-size: 0.68rem; }
        .mmc-sonda--stale .mmc-sonda-age { color: #d97706; opacity: 1; font-weight: 600; }

        /* CTA por piscina: alvo tátil ≥40px, encostado ao fundo do cartão. */
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
