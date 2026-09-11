<x-filament-panels::page>
    @php
        $kpis = $this->kpis;
        $bicoesGrouped = $this->bicoesGrouped;
        $ultimosMovimentos = $this->ultimosMovimentos;
    @endphp

    {{-- Ver a nota em list-daily-records.blade.php: os modais das ações são impressos
         no fim da vista da tabela, que aqui vive dentro do separador "inventario".
         Nos outros separadores esse contentor fica display:none e o "Reabastecer" dos
         bidões não abria nada. --}}
    <x-filament-actions::modals />

    <div class="space-y-6" x-data="{ tab: 'inventario' }">
        <!-- Top KPIs Strip (Apple / Tesla HIG) -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- KPI 1: Alertas Críticos -->
            <div class="rounded-2xl p-4 border transition-all duration-300 {{ $kpis['criticos_count'] > 0 ? 'bg-rose-50/70 border-rose-200 dark:bg-rose-950/20 dark:border-rose-900/50' : 'bg-emerald-50/70 border-emerald-200 dark:bg-emerald-950/20 dark:border-emerald-900/50' }}">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider {{ $kpis['criticos_count'] > 0 ? 'text-rose-700 dark:text-rose-400' : 'text-emerald-700 dark:text-emerald-400' }}">
                        Alertas de Reposição
                    </span>
                    <div class="w-8 h-8 rounded-full flex items-center justify-center {{ $kpis['criticos_count'] > 0 ? 'bg-rose-100 text-rose-600 dark:bg-rose-900/50 dark:text-rose-300' : 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/50 dark:text-emerald-300' }}">
                        @if ($kpis['criticos_count'] > 0)
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-5 h-5" />
                        @else
                            <x-filament::icon icon="heroicon-o-check-circle" class="w-5 h-5" />
                        @endif
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold {{ $kpis['criticos_count'] > 0 ? 'text-rose-900 dark:text-rose-100' : 'text-emerald-900 dark:text-emerald-100' }}">
                        {{ $kpis['criticos_count'] }}
                    </span>
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ $kpis['criticos_count'] === 1 ? 'produto abaixo do limite' : ($kpis['criticos_count'] > 1 ? 'produtos abaixo do limite' : 'Tudo abastecido') }}
                    </span>
                </div>
                <p class="text-xs mt-1 {{ $kpis['criticos_count'] > 0 ? 'text-rose-600 dark:text-rose-300' : 'text-emerald-600 dark:text-emerald-300' }}">
                    {{ $kpis['criticos_count'] > 0 ? 'Requer transferência ou encomenda urgente' : 'Piscinas com stock de reserva conforme' }}
                </p>
            </div>

            <!-- KPI 2: Armazém Geral -->
            <div class="rounded-2xl p-4 border border-slate-200 dark:border-slate-800 bg-white/70 dark:bg-slate-900/60 backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Armazém Central
                    </span>
                    <div class="w-8 h-8 rounded-full bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-600 dark:text-slate-300">
                        <x-filament::icon icon="heroicon-o-building-office-2" class="w-5 h-5" />
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold text-slate-900 dark:text-white">
                        {{ $kpis['armazem_litros'] }} <span class="text-base font-normal text-slate-400">L</span>
                    </span>
                    @if ($kpis['armazem_kg'] > 0)
                        <span class="text-sm font-semibold text-slate-600 dark:text-slate-300">
                            + {{ $kpis['armazem_kg'] }} kg
                        </span>
                    @endif
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {{ $kpis['total_produtos'] }} produtos químicos ativos no catálogo
                </p>
            </div>

            <!-- KPI 3: Bidões em Dosagem -->
            <div class="rounded-2xl p-4 border border-slate-200 dark:border-slate-800 bg-white/70 dark:bg-slate-900/60 backdrop-blur-md">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        Bidões na Sala de Máquinas
                    </span>
                    <div class="w-8 h-8 rounded-full flex items-center justify-center {{ $kpis['bicoes_baixos'] > 0 ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/50 dark:text-amber-300' : 'bg-blue-100 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300' }}">
                        <x-filament::icon icon="heroicon-o-beaker" class="w-5 h-5" />
                    </div>
                </div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold {{ $kpis['bicoes_baixos'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-slate-900 dark:text-white' }}">
                        {{ $kpis['bicoes_baixos'] }}
                    </span>
                    <span class="text-xs font-medium text-slate-500 dark:text-slate-400">
                        {{ $kpis['bicoes_baixos'] === 1 ? 'doseador em nível baixo' : ($kpis['bicoes_baixos'] > 1 ? 'doseadores em nível baixo' : 'a dosear normalmente') }}
                    </span>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    {{ $kpis['bicoes_total'] }} recipientes monitorizados em tempo real
                </p>
            </div>
        </div>

        <!-- Segmented Control Bar (Zero Scroll / 0ms Latency) -->
        <div class="flex items-center justify-center p-1.5 bg-slate-100/80 dark:bg-slate-800/80 rounded-2xl max-w-2xl mx-auto border border-slate-200/60 dark:border-slate-700/60 shadow-inner">
            <button 
                type="button"
                @click="tab = 'inventario'"
                :class="tab === 'inventario' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm font-semibold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-medium'"
                class="flex-1 py-3 px-4 rounded-xl text-sm transition-all duration-200 flex items-center justify-center gap-2 min-h-[44px]">
                <x-filament::icon icon="heroicon-o-cube" class="w-4 h-4" />
                <span>Inventário &amp; Armazém</span>
            </button>

            <button 
                type="button"
                @click="tab = 'bicoes'"
                :class="tab === 'bicoes' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm font-semibold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-medium'"
                class="flex-1 py-3 px-4 rounded-xl text-sm transition-all duration-200 flex items-center justify-center gap-2 min-h-[44px]">
                <x-filament::icon icon="heroicon-o-beaker" class="w-4 h-4" />
                <span>Bidões Ativos</span>
                @if ($kpis['bicoes_baixos'] > 0)
                    <span class="inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-bold leading-none text-white bg-amber-500 rounded-full">
                        {{ $kpis['bicoes_baixos'] }}
                    </span>
                @endif
            </button>

            <button 
                type="button"
                @click="tab = 'historico'"
                :class="tab === 'historico' ? 'bg-white dark:bg-slate-700 text-slate-900 dark:text-white shadow-sm font-semibold' : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-medium'"
                class="flex-1 py-3 px-4 rounded-xl text-sm transition-all duration-200 flex items-center justify-center gap-2 min-h-[44px]">
                <x-filament::icon icon="heroicon-o-clock" class="w-4 h-4" />
                <span>Últimos Movimentos</span>
            </button>
        </div>

        <!-- Tab 1: Inventário & Armazém (Tabela Consolidada com Tabs Nativas) -->
        <div x-show="tab === 'inventario'" x-cloak class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white tracking-tight">Posição de Stock</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Armazém central e stocks em cada instalação. Toque num valor para abastecer ou transferir.</p>
                </div>
            </div>

            <div class="overflow-x-auto rounded-2xl">
                {{ $this->table }}
            </div>
        </div>

        <!-- Tab 2: Bidões em Dosagem (Salas de Máquinas) -->
        <div x-show="tab === 'bicoes'" x-cloak class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white tracking-tight">Bidões nas Salas de Máquinas</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Recipientes atualmente ligados às bombas doseadoras das piscinas. Toque em reabastecer para registar a troca.</p>
                </div>
            </div>

            @if ($bicoesGrouped->isEmpty())
                <div class="py-12 text-center rounded-2xl border border-dashed border-slate-300 dark:border-slate-800 bg-white/50 dark:bg-slate-900/30">
                    <x-filament::icon icon="heroicon-o-beaker" class="w-12 h-12 mx-auto text-slate-400" />
                    <p class="mt-2 text-sm font-medium text-slate-600 dark:text-slate-300">Nenhum bidão de dosagem configurado</p>
                    <p class="text-xs text-slate-400">Os bidões são associados aos controladores automáticos das piscinas.</p>
                </div>
            @else
                <div class="space-y-8">
                    @foreach ($bicoesGrouped as $instalacaoNome => $bicoes)
                        <div class="space-y-3">
                            <div class="flex items-center gap-2 border-b border-slate-200 dark:border-slate-800 pb-2">
                                <x-filament::icon icon="heroicon-o-building-office" class="w-5 h-5 text-primary-500" />
                                <h4 class="font-bold text-base text-slate-800 dark:text-slate-200">{{ $instalacaoNome }}</h4>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                @foreach ($bicoes as $container)
                                    @php
                                        $pct = $container->percentagem();
                                        $capacidadeL = $container->capacidade_ml ? round($container->capacidade_ml / 1000, 1) : 25;
                                        $restanteL = $container->restante_ml !== null ? round((float) $container->restante_ml / 1000, 1) : 0;
                                        $nivel = $container->nivel();

                                        $barColor = match ($nivel) {
                                            'critico' => 'bg-rose-500',
                                            'aviso' => 'bg-amber-500',
                                            default => 'bg-emerald-500',
                                        };

                                        $badgeBg = match ($nivel) {
                                            'critico' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300 border-rose-200 dark:border-rose-900/40',
                                            'aviso' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 border-amber-200 dark:border-amber-900/40',
                                            default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border-emerald-200 dark:border-emerald-900/40',
                                        };
                                    @endphp

                                    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 shadow-sm space-y-3">
                                        <div class="flex items-start justify-between gap-2">
                                            <div>
                                                <h5 class="font-bold text-slate-900 dark:text-white text-base">
                                                    {{ $container->piscina?->name ?? 'Piscina' }}
                                                </h5>
                                                <div class="flex items-center gap-1.5 mt-0.5">
                                                    <span class="inline-block w-2.5 h-2.5 rounded-full {{ $container->tipo === 'cloro' ? 'bg-amber-500' : 'bg-blue-500' }}"></span>
                                                    <span class="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                        {{ $container->tipoLabel() }}
                                                    </span>
                                                    @if ($container->produto)
                                                        <span class="text-xs text-slate-400">· {{ $container->produto->name }}</span>
                                                    @endif
                                                </div>
                                            </div>

                                            <span class="px-2 py-0.5 text-xs font-semibold uppercase tracking-wider rounded-full border {{ $badgeBg }}">
                                                {{ $nivel === 'critico' ? 'Crítico' : ($nivel === 'aviso' ? 'Aviso' : 'OK') }}
                                            </span>
                                        </div>

                                        <!-- Level Progress Bar -->
                                        <div>
                                            <div class="flex justify-between items-center text-xs mb-1">
                                                <span class="font-semibold text-slate-700 dark:text-slate-200">
                                                    {{ $restanteL }} L <span class="text-slate-400 font-normal">/ {{ $capacidadeL }} L</span>
                                                </span>
                                                <span class="font-bold {{ $nivel === 'critico' ? 'text-rose-600 dark:text-rose-400' : 'text-slate-600 dark:text-slate-300' }}">
                                                    {{ $pct !== null ? $pct . '%' : 'N/D' }}
                                                </span>
                                            </div>
                                            <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-3 overflow-hidden">
                                                <div class="h-3 rounded-full {{ $barColor }} transition-all duration-500" style="width: {{ min(100, max(0, $pct ?? 0)) }}%"></div>
                                            </div>
                                        </div>

                                        <!-- Autonomia Preditiva -->
                                        @php
                                            $autonomiaTxt = $container->descricaoAutonomia(3);
                                            $esgotaFds = $container->esgotaNoFimDeSemana(3);
                                            $statusAuto = $container->statusAutonomia(3);
                                        @endphp
                                        <div class="flex items-center justify-between text-xs py-1.5 px-2.5 rounded-xl {{ $statusAuto === 'critico' ? 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-900/50' : ($statusAuto === 'aviso' ? 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-900/50' : 'bg-slate-50 dark:bg-slate-800/80 text-slate-600 dark:text-slate-300 border border-slate-100 dark:border-slate-800') }}">
                                            <span class="flex items-center gap-1.5 font-medium text-[11px]">
                                                <x-filament::icon icon="heroicon-m-clock" class="w-3.5 h-3.5 text-slate-400" />
                                                Autonomia: <strong>{{ $autonomiaTxt }}</strong>
                                            </span>
                                            @if ($esgotaFds)
                                                <span class="px-1.5 py-0.5 text-[9px] font-bold bg-amber-200 dark:bg-amber-800 text-amber-900 dark:text-white rounded uppercase tracking-wider">Fim de Semana</span>
                                            @endif
                                        </div>

                                        <!-- Last refill info & Action -->
                                        <div class="pt-2 border-t border-slate-100 dark:border-slate-800/80 flex items-center justify-between text-xs">
                                            <div class="text-slate-500 dark:text-slate-400 text-[11px]">
                                                @if ($container->reabastecido_em)
                                                    <span>Reposto {{ $container->reabastecido_em->diffForHumans() }}</span>
                                                    @if ($container->reabastecidoPor)
                                                        <span class="block text-slate-400">{{ $container->reabastecidoPor->name }}</span>
                                                    @endif
                                                @else
                                                    <span>Sem reposição registada</span>
                                                @endif
                                            </div>

                                            @if (auth()->user()?->hasAnyRole([\App\Constants\UserRole::ADMIN, \App\Constants\UserRole::TECNICO]))
                                                <button 
                                                    type="button"
                                                    wire:click="mountAction('reabastecerBidao', { container: {{ $container->id }} })"
                                                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-900 hover:bg-black text-white dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100 font-semibold text-xs transition-colors min-h-[44px]">
                                                    <x-filament::icon icon="heroicon-o-arrow-path" class="w-4 h-4" />
                                                    <span>Reabastecer</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Tab 3: Últimos Movimentos (Audit Trail Rápido) -->
        <div x-show="tab === 'historico'" x-cloak class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white tracking-tight">Histórico de Movimentos Recentes</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Últimas 10 entradas, saídas e transferências de químicos registadas.</p>
                </div>
            </div>

            @if ($ultimosMovimentos->isEmpty())
                <div class="py-12 text-center rounded-2xl border border-dashed border-slate-300 dark:border-slate-800 bg-white/50 dark:bg-slate-900/30">
                    <x-filament::icon icon="heroicon-o-clock" class="w-12 h-12 mx-auto text-slate-400" />
                    <p class="mt-2 text-sm font-medium text-slate-600 dark:text-slate-300">Sem histórico recente registado</p>
                </div>
            @else
                <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 overflow-hidden shadow-sm">
                    <div class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($ultimosMovimentos as $log)
                            <div class="p-4 flex items-center justify-between gap-4 hover:bg-slate-50/50 dark:hover:bg-slate-800/30 transition-colors">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center {{ $log->tipo_movimento === 'entrada' ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-blue-100 text-blue-600 dark:bg-blue-950 dark:text-blue-300' }}">
                                        @if ($log->tipo_movimento === 'entrada')
                                            <x-filament::icon icon="heroicon-o-arrow-down-tray" class="w-5 h-5" />
                                        @else
                                            <x-filament::icon icon="heroicon-o-truck" class="w-5 h-5" />
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-bold text-slate-900 dark:text-white text-sm">
                                            {{ $log->produto?->name ?? 'Produto Desconhecido' }}
                                        </div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-2 mt-0.5">
                                            <span class="font-medium text-slate-700 dark:text-slate-300">{{ $log->utilizador?->name ?? 'Sistema' }}</span>
                                            <span>•</span>
                                            <span>{{ $log->created_at ? $log->created_at->diffForHumans() : '—' }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold {{ $log->tipo_movimento === 'entrada' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300' }}">
                                        {{ $log->tipo_movimento === 'entrada' ? '+' : '-' }}{{ number_format((float) $log->quantity, 1, ',', ' ') }} {{ $log->produto?->unidade }}
                                    </span>
                                    <div class="text-[11px] text-slate-400 mt-1 capitalize">
                                        {{ $log->tipo_movimento }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
