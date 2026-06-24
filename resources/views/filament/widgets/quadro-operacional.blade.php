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
</x-filament-widgets::widget>
