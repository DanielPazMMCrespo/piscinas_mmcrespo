<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <span class="mmc-kb-heading">
                <x-filament::icon icon="heroicon-o-view-columns" class="mmc-kb-heading-icon" />
                Quadro de operação
            </span>
        </x-slot>
        <x-slot name="description">
            Arrasta os cartões entre colunas (ou usa os botões). {{ $conformesHoje }}/{{ $totalPiscinas }} piscinas conformes hoje.
        </x-slot>

        <div class="mmc-kb" x-data="mmcKanban" wire:key="quadro-operacional">
            @foreach ([
                'pendente' => ['titulo' => 'Para tratar', 'classe' => 'pendente', 'vazio' => 'Nada para tratar'],
                'em_curso' => ['titulo' => 'Em tratamento', 'classe' => 'curso', 'vazio' => 'Nenhum cartão em curso'],
                'resolvido' => ['titulo' => 'Resolvido hoje', 'classe' => 'feito', 'vazio' => 'Ainda nada resolvido hoje'],
            ] as $status => $col)
                <div class="mmc-kb-col mmc-kb-col--{{ $col['classe'] }}">
                    <div class="mmc-kb-col-head">
                        <span class="mmc-kb-col-title">{{ $col['titulo'] }}</span>
                        <span class="mmc-kb-count">{{ count($colunas[$status]) }}</span>
                    </div>

                    <div class="mmc-kb-list" data-status="{{ $status }}" x-ref="lista_{{ $status }}">
                        @forelse ($colunas[$status] as $a)
                            <div class="mmc-kb-card mmc-kb-card--{{ $a['nivel'] }}"
                                 data-key="{{ $a['key'] }}"
                                 wire:key="kb-{{ md5($a['key']) }}">
                                <div class="mmc-kb-card-top">
                                    <x-filament::icon :icon="$a['icone']" class="mmc-kb-card-icon" />
                                    <span class="mmc-kb-card-title">{{ $a['titulo'] }}</span>
                                </div>
                                <p class="mmc-kb-card-detail">{{ $a['detalhe'] }}</p>

                                <div class="mmc-kb-card-foot">
                                    <a href="{{ $a['url'] }}" class="mmc-kb-link">
                                        {{ $a['acao'] }}
                                        <x-filament::icon icon="heroicon-m-arrow-up-right" class="mmc-kb-link-icon" />
                                    </a>

                                    <span class="mmc-kb-card-meta">
                                        @if ($a['auto'])
                                            <span class="mmc-kb-auto">automático</span>
                                        @elseif ($a['movido_em'])
                                            {{ $a['movido_em'] }}
                                        @endif
                                    </span>
                                </div>

                                {{-- Fallback tátil: mover sem arrastar (mobile/teclado). --}}
                                <div class="mmc-kb-moves">
                                    @if ($status === 'pendente')
                                        <button type="button" class="mmc-kb-btn" wire:click="moverAlerta('{{ $a['key'] }}', 'em_curso')">▶ Iniciar</button>
                                        <button type="button" class="mmc-kb-btn mmc-kb-btn--ok" wire:click="moverAlerta('{{ $a['key'] }}', 'resolvido')">✓ Resolver</button>
                                    @elseif ($status === 'em_curso')
                                        <button type="button" class="mmc-kb-btn" wire:click="moverAlerta('{{ $a['key'] }}', 'pendente')">↩ Voltar</button>
                                        <button type="button" class="mmc-kb-btn mmc-kb-btn--ok" wire:click="moverAlerta('{{ $a['key'] }}', 'resolvido')">✓ Resolver</button>
                                    @else
                                        <button type="button" class="mmc-kb-btn" wire:click="moverAlerta('{{ $a['key'] }}', 'pendente')">↩ Reabrir</button>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <div class="mmc-kb-empty">{{ $col['vazio'] }}</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <style>
        .mmc-kb-heading { display: inline-flex; align-items: center; gap: 0.5rem; }
        .mmc-kb-heading-icon { width: 1.25rem; height: 1.25rem; opacity: 0.7; }

        /* Desktop: 3 colunas. Mobile: carrossel com scroll-snap (uma coluna ~85vw). */
        .mmc-kb {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.875rem;
            align-items: start;
        }
        @media (max-width: 768px) {
            .mmc-kb {
                display: flex;
                overflow-x: auto;
                scroll-snap-type: x mandatory;
                gap: 0.75rem;
                padding-bottom: 0.5rem;
                -webkit-overflow-scrolling: touch;
            }
            .mmc-kb-col { flex: 0 0 85%; scroll-snap-align: start; }
        }

        .mmc-kb-col {
            border-radius: 0.875rem;
            background: rgba(0, 0, 0, 0.025);
            border: 1px solid #e5e7eb;
            padding: 0.625rem;
            min-width: 0;
        }
        .dark .mmc-kb-col { background: rgba(255, 255, 255, 0.02); border-color: #3f3f46; }

        .mmc-kb-col-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.25rem 0.375rem 0.625rem;
        }
        .mmc-kb-col-title { font-weight: 700; font-size: 0.85rem; letter-spacing: 0.01em; }
        .mmc-kb-count {
            font-size: 0.75rem; font-weight: 700;
            min-width: 1.5rem; height: 1.5rem;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 9999px;
            background: rgba(0, 0, 0, 0.06);
        }
        .dark .mmc-kb-count { background: rgba(255, 255, 255, 0.08); }

        /* Acentos por coluna (linha no topo). */
        .mmc-kb-col--pendente { border-top: 3px solid #dc2626; }
        .mmc-kb-col--curso { border-top: 3px solid #d97706; }
        .mmc-kb-col--feito { border-top: 3px solid #16a34a; }

        .mmc-kb-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            min-height: 3rem;
        }

        .mmc-kb-card {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-left-width: 4px;
            border-radius: 0.75rem;
            padding: 0.75rem 0.875rem;
            cursor: grab;
            transition: box-shadow 0.15s ease, transform 0.15s ease;
        }
        .mmc-kb-card:hover { box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08); transform: translateY(-1px); }
        .mmc-kb-card:active { cursor: grabbing; }
        .dark .mmc-kb-card { background: rgba(255, 255, 255, 0.03); border-color: #3f3f46; }

        .mmc-kb-card--vermelho { border-left-color: #dc2626; }
        .mmc-kb-card--amarelo { border-left-color: #d97706; }
        .mmc-kb-card--neutro { border-left-color: #9ca3af; }

        /* Dark mode: manter cores nas bordas esquerda */
        .dark .mmc-kb-card--vermelho { border-left-color: #ef4444; }
        .dark .mmc-kb-card--amarelo { border-left-color: #f59e0b; }
        .dark .mmc-kb-card--neutro { border-left-color: #d1d5db; }

        /* Fantasma do drag (SortableJS). */
        .mmc-kb-ghost { opacity: 0.35; }
        .mmc-kb-drag { box-shadow: 0 12px 28px rgba(0, 0, 0, 0.18); transform: rotate(1.5deg); }

        .mmc-kb-card-top { display: flex; align-items: flex-start; gap: 0.5rem; }
        .mmc-kb-card-icon { width: 1.15rem; height: 1.15rem; flex: 0 0 auto; margin-top: 0.1rem; opacity: 0.75; }
        .mmc-kb-card--vermelho .mmc-kb-card-icon { color: #dc2626; opacity: 1; }
        .mmc-kb-card--amarelo .mmc-kb-card-icon { color: #d97706; opacity: 1; }

        /* Dark mode: ícones com cores mais brilhantes */
        .dark .mmc-kb-card--vermelho .mmc-kb-card-icon { color: #ef4444; }
        .dark .mmc-kb-card--amarelo .mmc-kb-card-icon { color: #f59e0b; }
        .mmc-kb-card-title { font-weight: 650; font-size: 0.85rem; line-height: 1.3; }
        .mmc-kb-card-detail { font-size: 0.75rem; opacity: 0.7; line-height: 1.35; margin: 0.35rem 0 0; }

        .mmc-kb-card-foot {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 0.5rem;
        }
        .mmc-kb-link {
            display: inline-flex; align-items: center; gap: 0.15rem;
            font-size: 0.75rem; font-weight: 650;
            color: #2b9cd8; text-decoration: none;
        }
        .mmc-kb-link-icon { width: 0.85rem; height: 0.85rem; }
        .mmc-kb-card-meta { font-size: 0.7rem; opacity: 0.55; }
        .mmc-kb-auto {
            font-size: 0.65rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.1rem 0.4rem; border-radius: 9999px;
            background: rgba(22, 163, 74, 0.12); color: #15803d;
        }
        .dark .mmc-kb-auto { background: rgba(34, 197, 94, 0.15); color: #4ade80; }

        /* Botões de movimento: alvos táteis ≥40px, escondidos em desktop até hover. */
        .mmc-kb-moves { display: flex; gap: 0.4rem; margin-top: 0.5rem; }
        .mmc-kb-btn {
            flex: 1 1 auto;
            min-height: 40px;
            font-size: 0.72rem; font-weight: 650;
            border-radius: 0.5rem;
            border: 1px solid #e5e7eb;
            background: rgba(0, 0, 0, 0.02);
            transition: background-color 0.12s ease;
        }
        .mmc-kb-btn:hover { background: rgba(0, 0, 0, 0.06); }
        .dark .mmc-kb-btn { border-color: #3f3f46; background: rgba(255, 255, 255, 0.04); }
        .dark .mmc-kb-btn:hover { background: rgba(255, 255, 255, 0.09); }
        .mmc-kb-btn--ok { color: #15803d; border-color: rgba(22, 163, 74, 0.35); }
        .dark .mmc-kb-btn--ok { color: #4ade80; }

        @media (hover: hover) and (min-width: 769px) {
            .mmc-kb-moves { opacity: 0; transition: opacity 0.15s ease; }
            .mmc-kb-card:hover .mmc-kb-moves, .mmc-kb-card:focus-within .mmc-kb-moves { opacity: 1; }
        }

        .mmc-kb-empty {
            font-size: 0.78rem; opacity: 0.45;
            text-align: center; padding: 1.25rem 0.5rem;
            border: 1px dashed #d1d5db; border-radius: 0.75rem;
        }
        .dark .mmc-kb-empty { border-color: #3f3f46; }
    </style>
</x-filament-widgets::widget>
