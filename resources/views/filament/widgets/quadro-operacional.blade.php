<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Alertas</x-slot>
        <x-slot name="description">{{ $conformesHoje }}/{{ $totalPiscinas }} piscinas conformes hoje.</x-slot>

        <div class="mmc-alert-list" wire:key="alertas-ativos">
            @forelse ($ativos as $a)
                @if ($a['grupo'] ?? false)
                    <div x-data="{ open: false }" wire:key="grupo-{{ $a['key'] }}">
                        <div class="mmc-alert-row mmc-alert-row--{{ $a['nivel'] }} mmc-alert-row--grupo" x-on:click="open = !open">
                            <x-filament::icon :icon="$a['icone']" class="mmc-alert-icon" />
                            <div class="mmc-alert-body">
                                <div class="mmc-alert-title">{{ $a['titulo'] }} ({{ count($a['subalertas']) }})</div>
                                <div class="mmc-alert-sub">{{ $a['detalhe'] }}</div>
                            </div>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="mmc-alert-grupo-chev"
                                x-bind:class="{ 'mmc-alert-grupo-chev--open': open }"
                            />
                        </div>
                        <div class="mmc-alert-subalertas" x-show="open" x-cloak>
                            @foreach ($a['subalertas'] as $sub)
                                <div class="mmc-subalert-row" wire:key="subalerta-{{ md5($sub['key']) }}">
                                    <span class="mmc-subalert-name">{{ trim(explode(':', $sub['titulo'])[0]) }}</span>
                                    @if ($sub['url'] && $sub['url'] !== '#')
                                        <a href="{{ $sub['url'] }}" class="mmc-subalert-link">{{ $sub['acao'] }}</a>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="mmc-alert-row mmc-alert-row--{{ $a['nivel'] }}" wire:key="alerta-{{ md5($a['key']) }}">
                        <x-filament::icon :icon="$a['icone']" class="mmc-alert-icon" />
                        <div class="mmc-alert-body">
                            <div class="mmc-alert-title">{{ $a['titulo'] }}</div>
                            <div class="mmc-alert-sub">{{ $a['detalhe'] }}</div>
                            @if ($a['url'] && $a['url'] !== '#')
                                <a href="{{ $a['url'] }}" class="mmc-alert-link">{{ $a['acao'] }}</a>
                            @endif
                        </div>
                        <button type="button" class="mmc-alert-resolve-btn" wire:click="moverAlerta('{{ $a['key'] }}', 'resolvido')">
                            Resolver
                        </button>
                    </div>
                @endif
            @empty
                <div class="mmc-alert-empty">Sem alertas ativos.</div>
            @endforelse
        </div>

        @if (count($resolvidos) > 0)
            <div x-data="{ open: false }" class="mmc-alert-resolved">
                <button type="button" class="mmc-alert-resolved-toggle" x-on:click="open = !open">
                    <span x-text="open ? 'Esconder resolvidos hoje ({{ count($resolvidos) }})' : 'Resolvidos hoje ({{ count($resolvidos) }})'"></span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="mmc-alert-resolved-chev" x-bind:class="{ 'mmc-alert-resolved-chev--open': open }" />
                </button>
                <div x-show="open" x-cloak class="mmc-alert-list">
                    @foreach ($resolvidos as $a)
                        <div class="mmc-alert-row mmc-alert-row--resolvido" wire:key="resolvido-{{ md5($a['key']) }}">
                            <x-filament::icon :icon="$a['icone']" class="mmc-alert-icon" />
                            <div class="mmc-alert-body">
                                <div class="mmc-alert-title">{{ $a['titulo'] }}</div>
                                <div class="mmc-alert-sub">
                                    @if ($a['auto'])
                                        <span class="mmc-alert-auto">automático</span>
                                    @else
                                        resolvido às {{ $a['movido_em'] }}
                                    @endif
                                </div>
                            </div>
                            <button type="button" class="mmc-alert-resolve-btn mmc-alert-resolve-btn--ghost" wire:click="moverAlerta('{{ $a['key'] }}', 'pendente')">
                                Reabrir
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
