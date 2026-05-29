<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Estado das Piscinas</x-slot>
        <x-slot name="description">Último registo válido de cada piscina. Verde = conforme CN 14/DA, vermelho = fora de limite.</x-slot>

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
                                Registado às {{ $item['registo']->registado_em->format('H:i') }}
                            @endif
                        </div>

                        <div class="mmc-metrics">
                            @foreach ($item['metricas'] as $m)
                                <div class="mmc-metric">
                                    <span class="mmc-metric-label">{{ $m['label'] }}</span>
                                    <span class="mmc-metric-value {{ $m['ok'] ? 'mmc-ok' : 'mmc-bad' }}">{{ $m['valor'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mmc-empty">Sem registos</div>
                    @endif
                </div>
            @empty
                <div class="mmc-empty">Nenhuma piscina ativa.</div>
            @endforelse
        </div>
    </x-filament::section>

    <style>
        .mmc-piscinas-grid {
            display: grid;
            /* min(100%, 240px) garante que em telemóvel estreito o cartão ocupa
               a largura toda em vez de transbordar. */
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr));
            gap: 0.75rem;
        }
        .mmc-card {
            border: 1px solid #e5e7eb;
            background: #ffffff;
            border-radius: 0.75rem;
            padding: 0.875rem 1rem;
            min-width: 0;
        }
        .dark .mmc-card {
            border-color: #3f3f46;
            background: rgba(255, 255, 255, 0.02);
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
        .mmc-metric-label { font-size: 0.7rem; opacity: 0.55; }
        .mmc-metric-value { font-size: 1.05rem; font-weight: 700; line-height: 1.2; }
        .mmc-ok { color: #16a34a; }
        .dark .mmc-ok { color: #22c55e; }
        .mmc-bad { color: #dc2626; }
        .dark .mmc-bad { color: #f87171; }
        .mmc-empty { opacity: 0.5; font-size: 0.85rem; padding: 0.5rem 0; }
    </style>
</x-filament-widgets::widget>
