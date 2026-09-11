<x-filament-panels::page>
    {{-- Filament Modals at root level (garante que modais de Page Actions como 'novaAcaoTecnica' abrem sempre, independente da tab ativa) --}}
    <x-filament-actions::modals />

    @php
        $kpis = $this->getKpis();
        $timeline = $this->getTimelineEvents();
        $piscinas = $this->getPiscinas();
        $isTecnicoOuAdmin = auth()->user()?->hasAnyRole([\App\Constants\UserRole::ADMIN, \App\Constants\UserRole::TECNICO]);
    @endphp

    {{-- Numa página com tabela, o Filament não imprime os modais das ações no
         componente da página: quem os imprime é o fim da vista da tabela. Com a
         tabela dentro de um separador x-show, o modal herdava o display:none do
         contentor e o botão "Ação Técnica" não abria nada. Imprimi-los aqui, antes
         da tabela, marca as flags has*ModalRendered e anula a impressão duplicada. --}}
    <x-filament-actions::modals />

    <div x-data="{
        activeTab: new URLSearchParams(window.location.search).get('view') || 'timeline'
    }" class="space-y-6">

        {{-- 1. Top KPIs Strip (Apple HIG Style) --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            {{-- KPI 1: Medições Hoje --}}
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm relative overflow-hidden">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Medições Hoje</span>
                    <div class="w-8 h-8 rounded-full bg-sky-50 dark:bg-sky-950/50 flex items-center justify-center text-sky-600 dark:text-sky-400">
                        <x-heroicon-o-clipboard-document-check class="w-5 h-5" />
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl md:text-3xl font-bold tracking-tight text-slate-900 dark:text-white tabular-nums">
                        {{ $kpis['medicoes_hoje'] }}
                    </span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">registos</span>
                </div>
                <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    <span class="font-medium text-slate-700 dark:text-slate-300">{{ $kpis['piscinas_medidas'] }}/{{ $kpis['total_piscinas'] }}</span> piscinas cobertas
                </div>
            </div>

            {{-- KPI 2: Filtração & Lavagens --}}
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm relative overflow-hidden">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Última Retrolavagem</span>
                    <div class="w-8 h-8 rounded-full bg-cyan-50 dark:bg-cyan-950/50 flex items-center justify-center text-cyan-600 dark:text-cyan-400">
                        <x-heroicon-o-arrow-path class="w-5 h-5" />
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    @if ($kpis['ultima_lavagem'])
                        <span class="text-xl md:text-2xl font-bold tracking-tight text-slate-900 dark:text-white truncate max-w-[200px]" title="{{ $kpis['ultima_lavagem']->piscina?->name }}">
                            {{ $kpis['ultima_lavagem']->piscina?->name }}
                        </span>
                    @else
                        <span class="text-xl md:text-2xl font-bold tracking-tight text-slate-400">
                            Sem registos
                        </span>
                    @endif
                </div>
                <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    @if ($kpis['ultima_lavagem'])
                        Há {{ $kpis['ultima_lavagem']->registado_em->diffForHumans(null, true) }} ({{ $kpis['ultima_lavagem']->registado_em->format('d/m H:i') }})
                    @else
                        Nenhuma retrolavagem recente
                    @endif
                </div>
            </div>

            {{-- KPI 3: Água & Contadores --}}
            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm relative overflow-hidden">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Renovação de Água (Mês)</span>
                    <div class="w-8 h-8 rounded-full bg-amber-50 dark:bg-amber-950/50 flex items-center justify-center text-amber-600 dark:text-amber-400">
                        <x-heroicon-o-scale class="w-5 h-5" />
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-2xl md:text-3xl font-bold tracking-tight text-slate-900 dark:text-white tabular-nums">
                        {{ $kpis['renovacoes_mes'] }}
                    </span>
                    <span class="text-xs text-slate-500 dark:text-slate-400">renovações</span>
                </div>
                <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    <span class="font-medium text-slate-700 dark:text-slate-300">{{ $kpis['contadores_mes'] }}</span> leituras de contador este mês
                </div>
            </div>
        </div>

        {{-- 2. Navigation Tabs (0ms Alpine.js Segmented Control) --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-200 dark:border-slate-800 pb-3">
            <div class="inline-flex p-1 bg-slate-100 dark:bg-slate-800/80 rounded-xl border border-slate-200/60 dark:border-slate-700/60 w-full sm:w-auto">
                <button
                    type="button"
                    @click="activeTab = 'timeline'"
                    :class="activeTab === 'timeline' ? 'bg-white dark:bg-slate-900 text-sky-600 dark:text-sky-400 shadow-sm font-semibold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium'"
                    class="flex-1 sm:flex-none px-4 py-2.5 text-xs md:text-sm rounded-lg transition-all flex items-center justify-center gap-2 min-h-[44px]"
                >
                    <x-heroicon-m-clock class="w-4 h-4" />
                    <span>Linha Temporal</span>
                </button>

                <button
                    type="button"
                    @click="activeTab = 'leituras'"
                    :class="activeTab === 'leituras' ? 'bg-white dark:bg-slate-900 text-emerald-600 dark:text-emerald-400 shadow-sm font-semibold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium'"
                    class="flex-1 sm:flex-none px-4 py-2.5 text-xs md:text-sm rounded-lg transition-all flex items-center justify-center gap-2 min-h-[44px]"
                >
                    <x-heroicon-m-table-cells class="w-4 h-4" />
                    <span>Medições de Água</span>
                </button>

                @if ($isTecnicoOuAdmin)
                    <button
                        type="button"
                        @click="activeTab = 'acoes'"
                        :class="activeTab === 'acoes' ? 'bg-white dark:bg-slate-900 text-amber-600 dark:text-amber-400 shadow-sm font-semibold' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium'"
                        class="flex-1 sm:flex-none px-4 py-2.5 text-xs md:text-sm rounded-lg transition-all flex items-center justify-center gap-2 min-h-[44px]"
                    >
                        <x-heroicon-m-wrench-screwdriver class="w-4 h-4" />
                        <span>Ações Técnicas</span>
                    </button>
                @endif
            </div>

            {{-- Filtro de piscina para Timeline e Ações --}}
            <div x-show="activeTab !== 'leituras'" class="flex items-center gap-2">
                <select
                    wire:change="filterByPool($event.target.value ? parseInt($event.target.value) : null)"
                    class="text-xs bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-700 rounded-lg px-3 py-2 text-slate-700 dark:text-slate-200 focus:ring-sky-500 focus:border-sky-500 min-h-[40px]"
                >
                    <option value="">Todas as piscinas</option>
                    @foreach ($piscinas as $p)
                        <option value="{{ $p->id }}" {{ $this->filterPoolId === $p->id ? 'selected' : '' }}>
                            {{ $p->nome_completo }}
                        </option>
                    @endforeach
                </select>

                @if ($this->filterPoolId)
                    <button
                        type="button"
                        wire:click="filterByPool(null)"
                        class="text-xs text-slate-500 hover:text-slate-800 dark:hover:text-white underline px-2 py-1"
                    >
                        Limpar
                    </button>
                @endif
            </div>
        </div>

        {{-- 3. TAB 1: LINHA TEMPORAL CONSOLIDADA (Causa & Efeito) --}}
        <div x-show="activeTab === 'timeline'" x-cloak class="space-y-4">
            {{-- Subfiltros tipo na timeline --}}
            <div class="flex items-center gap-2 text-xs flex-wrap">
                <span class="text-slate-500 dark:text-slate-400 font-medium">Filtrar:</span>
                <button
                    type="button"
                    wire:click="filterByType(null)"
                    class="px-2.5 py-1 rounded-full border transition-all {{ $this->filterTipo === null ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900 border-transparent font-semibold' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-50' }}"
                >
                    Tudo
                </button>
                <button
                    type="button"
                    wire:click="filterByType('medicoes')"
                    class="px-2.5 py-1 rounded-full border transition-all {{ $this->filterTipo === 'medicoes' ? 'bg-sky-600 text-white border-transparent font-semibold' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-50' }}"
                >
                    Apenas Medições
                </button>
                @if ($isTecnicoOuAdmin)
                    <button
                        type="button"
                        wire:click="filterByType('acoes')"
                        class="px-2.5 py-1 rounded-full border transition-all {{ $this->filterTipo === 'acoes' ? 'bg-amber-600 text-white border-transparent font-semibold' : 'bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-700 hover:bg-slate-50' }}"
                    >
                        Apenas Ações Técnicas
                    </button>
                @endif
            </div>

            @if ($timeline->isEmpty())
                <div class="text-center py-12 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl">
                    <x-heroicon-o-clock class="w-12 h-12 text-slate-400 mx-auto mb-3" />
                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Sem eventos registados</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Os registos de água e intervenções técnicas aparecerão aqui cronologicamente.</p>
                </div>
            @else
                <div class="relative pl-6 space-y-6 before:content-[''] before:absolute before:top-2 before:bottom-2 before:left-[11px] before:w-[2px] before:bg-slate-200 dark:before:bg-slate-800">
                    @foreach ($timeline as $event)
                        @php
                            $isMedicao = $event['tipo_evento'] === 'medicao';
                            $ts = $event['timestamp'];
                        @endphp

                        <div class="relative group">
                            {{-- Dot on line --}}
                            <div class="absolute -left-6 top-3.5 w-[14px] h-[14px] rounded-full border-2 border-white dark:border-slate-900 shadow-sm {{ $isMedicao ? 'bg-sky-500' : 'bg-amber-500' }}"></div>

                            {{-- Card --}}
                            <div class="bg-white dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 rounded-xl p-4 shadow-sm hover:shadow transition-shadow">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 dark:border-slate-800/80 pb-2.5 mb-3">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-sm text-slate-900 dark:text-white">
                                            {{ $event['piscina_nome'] }}
                                        </span>
                                        @if ($event['instalacao_nome'])
                                            <span class="text-xs text-slate-400 dark:text-slate-500">
                                                • {{ $event['instalacao_nome'] }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                        <span class="tabular-nums font-medium text-slate-700 dark:text-slate-300">
                                            {{ $ts?->format('d/m/Y H:i') }}
                                        </span>
                                        <span>({{ $ts?->diffForHumans() }})</span>
                                        @if ($isMedicao)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-sky-50 dark:bg-sky-950/60 text-sky-700 dark:text-sky-300 border border-sky-200/60 dark:border-sky-800/60">
                                                Medição
                                            </span>
                                        @else
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 border border-amber-200/60 dark:border-amber-800/60">
                                                {{ $event['sub_tipo_label'] }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                {{-- Conteúdo do Evento --}}
                                @if ($isMedicao)
                                    <div class="flex flex-wrap items-center gap-2">
                                        {{-- pH --}}
                                        @if ($event['ph'] !== null)
                                            <div class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 text-xs flex items-center gap-1.5">
                                                <span class="text-slate-500 dark:text-slate-400 font-medium">pH</span>
                                                <span class="font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format((float)$event['ph'], 2) }}</span>
                                            </div>
                                        @endif

                                        {{-- Cloro Livre --}}
                                        @if ($event['cloro_livre'] !== null)
                                            <div class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 text-xs flex items-center gap-1.5">
                                                <span class="text-slate-500 dark:text-slate-400 font-medium">Cloro Livre</span>
                                                <span class="font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format((float)$event['cloro_livre'], 2) }} <span class="text-[10px] font-normal text-slate-400">mg/L</span></span>
                                            </div>
                                        @endif

                                        {{-- Cloro Combinado --}}
                                        @if ($event['cloro_combinado'] !== null)
                                            <div class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 text-xs flex items-center gap-1.5">
                                                <span class="text-slate-500 dark:text-slate-400 font-medium">Cloro Comb.</span>
                                                <span class="font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format((float)$event['cloro_combinado'], 2) }}</span>
                                            </div>
                                        @endif

                                        {{-- Temperatura --}}
                                        @if ($event['temperatura'] !== null)
                                            <div class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 text-xs flex items-center gap-1.5">
                                                <span class="text-slate-500 dark:text-slate-400 font-medium">Temp.</span>
                                                <span class="font-bold tabular-nums text-slate-900 dark:text-white">{{ number_format((float)$event['temperatura'], 1) }} ºC</span>
                                            </div>
                                        @endif

                                        {{-- ORP --}}
                                        @if ($event['orp'] !== null)
                                            <div class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 text-xs flex items-center gap-1.5">
                                                <span class="text-slate-500 dark:text-slate-400 font-medium">ORP</span>
                                                <span class="font-bold tabular-nums text-slate-900 dark:text-white">{{ $event['orp'] }} mV</span>
                                            </div>
                                        @endif

                                        {{-- Banhistas --}}
                                        @if ($event['banhistas'] !== null && $event['banhistas'] > 0)
                                            <div class="px-2.5 py-1 rounded-lg bg-slate-50 dark:bg-slate-800/50 border border-slate-200/60 dark:border-slate-700/60 text-xs flex items-center gap-1.5">
                                                <span class="text-slate-500 dark:text-slate-400 font-medium">Banhistas</span>
                                                <span class="font-bold tabular-nums text-slate-900 dark:text-white">{{ $event['banhistas'] }}</span>
                                            </div>
                                        @endif

                                        @if ($event['e_correcao'])
                                            <span class="text-[10px] font-semibold text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 px-2 py-0.5 rounded border border-amber-200 dark:border-amber-800">
                                                Correção
                                            </span>
                                        @endif
                                    </div>
                                @else
                                    {{-- Ação Técnica --}}
                                    <div class="space-y-2">
                                        <div class="flex flex-wrap items-center gap-2 text-xs">
                                            @if (in_array($event['sub_tipo'], [\App\Models\OperationalAction::TIPO_LAVAGEM_FILTRO, \App\Models\OperationalAction::TIPO_ENXAGUAMENTO_FILTRO], true))
                                                @if (!empty($event['dados']['filtro_nome']))
                                                    <span class="font-semibold text-slate-700 dark:text-slate-300">Filtro: {{ $event['dados']['filtro_nome'] }}</span>
                                                @endif
                                                @if (!empty($event['dados']['duracao_min']))
                                                    <span class="px-2 py-0.5 rounded bg-cyan-50 dark:bg-cyan-950/50 text-cyan-700 dark:text-cyan-300 border border-cyan-200 dark:border-cyan-800 font-medium">
                                                        ⏱️ {{ $event['dados']['duracao_min'] }} minutos
                                                    </span>
                                                @endif
                                                @if (isset($event['dados']['pressao_antes_bar']) || isset($event['dados']['pressao_depois_bar']))
                                                    <span class="text-slate-600 dark:text-slate-400">
                                                        Pressão: {{ $event['dados']['pressao_antes_bar'] ?? '—' }} → {{ $event['dados']['pressao_depois_bar'] ?? '—' }} bar
                                                    </span>
                                                @endif
                                                <span class="text-[10px] text-slate-500 italic">
                                                    (Sensor Hanna protegido por 30m)
                                                </span>
                                            @elseif ($event['sub_tipo'] === \App\Models\OperationalAction::TIPO_CONTADOR)
                                                <span class="font-bold text-sm text-slate-900 dark:text-white tabular-nums">
                                                    {{ $event['dados']['contador_valor'] ?? '—' }} m³
                                                </span>
                                            @elseif ($event['sub_tipo'] === \App\Models\OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                                                <span class="font-semibold text-slate-800 dark:text-slate-200">
                                                    Bidão {{ $event['dados']['bidao_tipo'] ?? 'químico' }}
                                                </span>
                                                @if (!empty($event['dados']['quantidade_l']))
                                                    <span class="px-2 py-0.5 rounded bg-emerald-50 dark:bg-emerald-950/50 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 font-medium">
                                                        +{{ $event['dados']['quantidade_l'] }} L
                                                    </span>
                                                @else
                                                    <span class="text-slate-500">(Enchimento completo)</span>
                                                @endif
                                            @elseif ($event['sub_tipo'] === \App\Models\OperationalAction::TIPO_TORNEIRA)
                                                <span class="font-semibold text-slate-700 dark:text-slate-300">
                                                    Modo: {{ $event['dados']['agua_modo'] ?? '—' }}
                                                </span>
                                            @elseif ($event['sub_tipo'] === \App\Models\OperationalAction::TIPO_BOMBA)
                                                <span class="font-semibold text-slate-700 dark:text-slate-300">
                                                    Bomba: {{ $event['dados']['bomba_nome'] ?? 'Principal' }} ({{ $event['dados']['bomba_acao'] ?? 'Normal' }})
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                @endif

                                {{-- Observações e Autor --}}
                                <div class="mt-2.5 pt-2 border-t border-slate-100 dark:border-slate-800/60 flex items-center justify-between text-xs text-slate-400">
                                    <div class="flex items-center gap-1.5">
                                        <x-heroicon-m-user class="w-3.5 h-3.5" />
                                        <span>{{ $event['autor'] }}</span>
                                    </div>

                                    @if (!empty($event['observacoes']))
                                        <div class="text-slate-600 dark:text-slate-400 italic truncate max-w-xs" title="{{ $event['observacoes'] }}">
                                            "{{ $event['observacoes'] }}"
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- 4. TAB 2: MEDIÇÕES DE ÁGUA (Tabela Nativa com abas DGS) --}}
        <div x-show="activeTab === 'leituras'" x-cloak>
            {{ $this->table }}
        </div>

        {{-- 5. TAB 3: INTERVENÇÕES MÁQUINAS (Ações Técnicas) --}}
        @if ($isTecnicoOuAdmin)
            <div x-show="activeTab === 'acoes'" x-cloak class="space-y-4">
                @php
                    $acoesList = $this->getAcoesTecnicas();
                @endphp

                @if ($acoesList->isEmpty())
                    <div class="text-center py-12 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl">
                        <x-heroicon-o-wrench-screwdriver class="w-12 h-12 text-slate-400 mx-auto mb-3" />
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Sem ações técnicas recentes</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Use o botão "Ação Técnica" no topo para registar manutenções.</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        @foreach ($acoesList as $acao)
                            <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-4 shadow-sm hover:border-slate-300 dark:hover:border-slate-700 transition-all flex flex-col justify-between">
                                <div>
                                    <div class="flex items-center justify-between gap-2 mb-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-50 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                            {{ \App\Models\OperationalAction::TIPOS[$acao->tipo] ?? $acao->tipo }}
                                        </span>
                                        <span class="text-xs text-slate-400 tabular-nums">
                                            {{ $acao->registado_em->format('d/m H:i') }}
                                        </span>
                                    </div>

                                    <h4 class="font-bold text-sm text-slate-900 dark:text-white">
                                        {{ $acao->piscina?->nome_completo ?? $acao->piscina?->name }}
                                    </h4>

                                    {{-- Dados específicos --}}
                                    <div class="mt-3 space-y-1.5 text-xs text-slate-600 dark:text-slate-300">
                                        @if (in_array($acao->tipo, [\App\Models\OperationalAction::TIPO_LAVAGEM_FILTRO, \App\Models\OperationalAction::TIPO_ENXAGUAMENTO_FILTRO], true))
                                            <div>Duração: <span class="font-semibold text-slate-900 dark:text-white">{{ $acao->dados['duracao_min'] ?? 3 }} min</span></div>
                                            @if (!empty($acao->dados['filtro_nome']))
                                                <div>Filtro: <span class="font-semibold">{{ $acao->dados['filtro_nome'] }}</span></div>
                                            @endif
                                            @if (isset($acao->dados['pressao_antes_bar']) || isset($acao->dados['pressao_depois_bar']))
                                                <div>Pressão: {{ $acao->dados['pressao_antes_bar'] ?? '—' }} → {{ $acao->dados['pressao_depois_bar'] ?? '—' }} bar</div>
                                            @endif
                                        @elseif ($acao->tipo === \App\Models\OperationalAction::TIPO_CONTADOR)
                                            <div>Leitura: <span class="font-bold text-slate-900 dark:text-white">{{ $acao->dados['contador_valor'] ?? '—' }} m³</span></div>
                                        @elseif ($acao->tipo === \App\Models\OperationalAction::TIPO_REABASTECIMENTO_BIDAO)
                                            <div>Bidão: <span class="font-semibold">{{ $acao->dados['bidao_tipo'] ?? 'Químico' }}</span></div>
                                            <div>Quantidade: <span class="font-semibold">{{ $acao->dados['quantidade_l'] ?? 'Cheio' }} L</span></div>
                                        @elseif ($acao->tipo === \App\Models\OperationalAction::TIPO_TORNEIRA)
                                            <div>Modo: <span class="font-semibold">{{ $acao->dados['agua_modo'] ?? '—' }}</span></div>
                                        @elseif ($acao->tipo === \App\Models\OperationalAction::TIPO_BOMBA)
                                            <div>Bomba: {{ $acao->dados['bomba_nome'] ?? '—' }} ({{ $acao->dados['bomba_acao'] ?? '—' }})</div>
                                        @endif

                                        @if (!empty($acao->observacoes))
                                            <div class="mt-2 pt-2 border-t border-slate-100 dark:border-slate-800 text-slate-500 dark:text-slate-400 italic">
                                                "{{ $acao->observacoes }}"
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between text-xs text-slate-400">
                                    <span>Por {{ $acao->utilizador?->name ?? 'Técnico' }}</span>
                                    <a href="/admin/operational-actions/{{ $acao->id }}" class="text-sky-600 dark:text-sky-400 hover:underline font-medium">
                                        Ver detalhes →
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
