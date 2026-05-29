<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Evolução dos Parâmetros — Últimos 14 Dias</x-slot>
        <x-slot name="description">
            Faixa verde = intervalo conforme CN 14/DA. Uma piscina mostra todos os parâmetros juntos
            (para ver se o cloro acompanha o pH); várias piscinas mostram um gráfico por parâmetro.
        </x-slot>

        <div class="mb-5">
            {{ $this->form }}
        </div>

        @php($graficos = $this->getGraficos())

        <div
            wire:key="mmc-graficos-{{ md5(json_encode($graficos)) }}"
            class="mmc-graficos-grid"
        >
            @forelse ($graficos as $g)
                <div
                    @class([
                        'mmc-grafico-card',
                        'mmc-grafico-full' => ($g['modo'] ?? '') === 'multi-metrica',
                    ])
                    x-data="mmcChart({{ Illuminate\Support\Js::from($g) }})"
                >
                    <div class="mmc-grafico-titulo">
                        {{ $g['titulo'] }}
                        @if (($g['modo'] ?? '') === 'mono-metrica' && $g['unidade'])
                            <span class="mmc-grafico-unidade">({{ $g['unidade'] }})</span>
                        @endif
                        @if (($g['modo'] ?? '') === 'mono-metrica' && $g['banda'])
                            <span class="mmc-grafico-legenda-banda">
                                conforme: {{ str_replace('.', ',', (string) $g['banda']['min']) }}–{{ str_replace('.', ',', (string) $g['banda']['max']) }}
                            </span>
                        @endif
                    </div>

                    <div class="mmc-grafico-canvas-wrap {{ ($g['modo'] ?? '') === 'multi-metrica' ? 'mmc-canvas-alto' : '' }}">
                        <canvas x-ref="canvas"></canvas>
                    </div>

                    <script type="application/json" x-ref="payload">{{ Illuminate\Support\Js::from($g) }}</script>
                </div>
            @empty
                <div class="mmc-grafico-vazio">
                    Seleciona pelo menos uma piscina e um parâmetro.
                </div>
            @endforelse
        </div>
    </x-filament::section>

    <style>
        .mmc-graficos-grid {
            display: grid;
            /* minmax com min:0 deixa o cartão encolher abaixo da largura do conteúdo;
               o min(100%, 380px) garante 1 coluna em telemóvel sem transbordar. */
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr));
            gap: 1rem;
        }
        .mmc-grafico-card {
            border: 1px solid #e5e7eb;
            border-radius: 0.75rem;
            padding: 0.875rem 1rem 0.5rem;
            background: #ffffff;
            min-width: 0; /* permite encolher dentro da grelha */
        }
        .mmc-grafico-full { grid-column: 1 / -1; }
        .dark .mmc-grafico-card {
            border-color: #3f3f46;
            background: rgba(255, 255, 255, 0.02);
        }
        .mmc-grafico-titulo {
            font-weight: 700;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: baseline;
            gap: 0.4rem;
            flex-wrap: wrap; /* o badge de conformidade quebra para baixo em vez de transbordar */
        }
        .mmc-grafico-unidade { font-weight: 400; opacity: 0.55; font-size: 0.78rem; }
        .mmc-grafico-legenda-banda {
            margin-left: auto;
            font-weight: 600;
            font-size: 0.72rem;
            color: #16a34a;
            white-space: nowrap;
        }
        .dark .mmc-grafico-legenda-banda { color: #4ade80; }
        .mmc-grafico-canvas-wrap { position: relative; height: 240px; }
        .mmc-canvas-alto { height: 320px; }
        .mmc-grafico-vazio {
            grid-column: 1 / -1;
            opacity: 0.55;
            font-size: 0.9rem;
            padding: 2rem 0;
            text-align: center;
        }

        /* Telemóvel: gráficos mais baixos para caber sem scroll exagerado,
           legenda do badge alinhada à esquerda (já não há espaço à direita). */
        @media (max-width: 640px) {
            .mmc-graficos-grid { gap: 0.75rem; }
            .mmc-grafico-card { padding: 0.75rem 0.75rem 0.4rem; }
            .mmc-grafico-canvas-wrap { height: 200px; }
            .mmc-canvas-alto { height: 240px; }
            .mmc-grafico-legenda-banda { margin-left: 0; }
        }
    </style>
</x-filament-widgets::widget>
