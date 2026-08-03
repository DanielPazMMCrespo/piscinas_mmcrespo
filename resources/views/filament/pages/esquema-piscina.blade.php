<x-filament-panels::page>
    @php
        $pill = [
            'critico' => ['Ação', 'mmc-vg-pill--crit'],
            'aviso' => ['Atenção', 'mmc-vg-pill--warn'],
            'ok' => ['Conforme', 'mmc-vg-pill--ok'],
            'sem_dados' => ['Sem dados', 'mmc-vg-pill--neutro'],
        ];
    @endphp

    @if (empty($estados))
        <x-filament::section>
            <p class="text-sm text-gray-500">Sem piscinas disponíveis.</p>
        </x-filament::section>
    @else
        {{-- Seletor de instalação --}}
        <div class="mmc-esq-tabs">
            <span class="mmc-esq-tabs__grupo">Instalação</span>
            @foreach ($instalacoes as $inst)
                <button
                    type="button"
                    wire:click="selecionarInstalacao({{ $inst['id'] }})"
                    class="mmc-esq-tabs__tab {{ $inst['id'] === $instalacaoAtiva ? 'mmc-esq-tabs__tab--ativa' : '' }}"
                >
                    {{ $inst['nome'] }}
                </button>
            @endforeach
        </div>

        <div wire:poll.60s wire:key="esquema-instalacao-{{ $instalacaoAtiva }}" x-data="{ destaque: null }">
            {{-- 1. Visão geral da instalação --}}
            <x-filament::section>
                <x-slot name="heading">Visão geral</x-slot>
                <x-slot name="description">Estado de cada piscina da instalação e dos bidões de dosagem. Clique numa piscina para ir ao circuito.</x-slot>

                <div class="mmc-vg">
                    @foreach ($estados as $e)
                        @php
                            $p = $e['piscina'];
                            [$pillTxt, $pillCls] = $pill[$e['estado_geral']] ?? $pill['sem_dados'];
                            $tanqueDot = $e['tanque'] === null ? null : match ($e['tanque']['estado']) {
                                'ok' => 'g', 'verificar' => 'a', default => 'n',
                            };
                            $dots = [
                                ['Torneira', match ($e['torneira']['estado']) {
                                    'aberta' => 'r', 'com_agua', 'fechada' => 'g', default => 'n',
                                }],
                                ['Bomba', match ($e['bomba']['estado']) {
                                    'a_trabalhar' => 'g', 'parada' => 'a', default => 'n',
                                }],
                                ['Filtro', $e['filtro']['lavado_hoje'] ? 'g' : 'n'],
                            ];
                            if ($tanqueDot !== null) {
                                $dots[] = ['Tanque', $tanqueDot];
                            }
                        @endphp
                        <button
                            type="button"
                            class="mmc-vg-card mmc-vg-card--{{ $e['estado_geral'] }}"
                            x-on:mouseenter="destaque = {{ $p->id }}"
                            x-on:mouseleave="destaque = null"
                            x-on:click="document.getElementById('circuito-{{ $p->id }}')?.scrollIntoView({ behavior: 'smooth', block: 'center' })"
                        >
                            <div class="mmc-vg-card__top">
                                <div>
                                    <div class="mmc-vg-card__nome">{{ $p->name }}</div>
                                    <div class="mmc-vg-card__vol">{{ $p->volume !== null ? number_format((float) $p->volume, 0, ',', ' ') . ' m³' : '' }}</div>
                                </div>
                                <span class="mmc-vg-pill {{ $pillCls }}">{{ $pillTxt }}</span>
                            </div>

                            <div class="mmc-vg-card__chips">
                                @foreach ($e['agua']['valores'] as $v)
                                    <span class="mmc-vg-chip {{ $v['ok'] === false ? 'mmc-vg-chip--mau' : ($v['ok'] === null ? 'mmc-vg-chip--neutro' : 'mmc-vg-chip--ok') }}">
                                        <span class="mmc-vg-chip__k">{{ $v['label'] }}</span>
                                        <span class="mmc-vg-chip__v">{{ $v['valor'] }}</span>
                                    </span>
                                @endforeach
                            </div>

                            @if (! empty($e['sonda_avaria']))
                                <div class="mmc-vg-card__chips">
                                    <span class="mmc-vg-chip mmc-vg-chip--neutro">
                                        <span class="mmc-vg-chip__k">Sonda</span>
                                        <span class="mmc-vg-chip__v">{{ $e['sonda_avaria']['motivo'] }}</span>
                                    </span>
                                </div>
                            @endif

                            <div class="mmc-vg-card__dots">
                                @foreach ($dots as [$nome, $cor])
                                    <span class="mmc-vg-dot"><span class="mmc-vg-dot__c mmc-vg-dot__c--{{ $cor }}"></span>{{ $nome }}</span>
                                @endforeach
                            </div>

                            @if (! empty($e['bidoes']))
                                <div class="mmc-vg-card__bidoes">
                                    @foreach ($e['bidoes'] as $b)
                                        @include('filament.pages.partials.esquema-bidao', ['b' => $b, 'grande' => false])
                                    @endforeach
                                </div>
                            @endif
                        </button>
                    @endforeach
                </div>
            </x-filament::section>

            {{-- 2. Detalhe por piscina --}}
            <x-filament::section class="mmc-esq-detalhe-seccao">
                <x-slot name="heading">Detalhe por piscina</x-slot>

                <div class="mmc-esq-stack">
                    @foreach ($estados as $e)
                        <div
                            class="mmc-esq-stack__item"
                            x-bind:class="{ 'mmc-esq-stack__item--destaque': destaque === {{ $e['piscina']->id }} }"
                        >
                            @include('filament.pages.partials.esquema-circuito', ['e' => $e])
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
