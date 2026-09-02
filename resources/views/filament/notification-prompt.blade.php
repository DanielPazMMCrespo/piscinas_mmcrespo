@php
    $user = auth()->user();
    $textoPrompt = 'Receba notificações importantes da aplicação diretamente no seu dispositivo.';
    $pedidoAdminPendente = false;

    if ($user) {
        $pedidoAdminPendente = ! $user->hasPushActive()
            && $user->unreadNotifications()
                ->where('type', \App\Notifications\PedidoAtivacaoPushNotification::class)
                ->exists();
    }

    if ($user) {
        if ($user->hasRole(\App\Constants\UserRole::ADMIN)) {
            $textoPrompt = 'Receba alertas de novos incidentes, atualizações de sistema e notificações de controlo de todas as piscinas.';
        } elseif ($user->hasRole(\App\Constants\UserRole::TECNICO)) {
            $textoPrompt = 'Receba avisos imediatos de novos incidentes e do fim do timer de retrolavagem no seu telemóvel ou computador.';
        } elseif ($user->hasRole(\App\Constants\UserRole::NADADOR_SALVADOR)) {
            $textoPrompt = 'Receba alertas imediatos de incidentes e avisos importantes de segurança das suas piscinas diretamente no seu telemóvel.';
        } elseif ($user->hasRole(\App\Constants\UserRole::GESTOR)) {
            $textoPrompt = 'Acompanhe a conformidade das piscinas, alertas de incidentes graves e resumos de operação no seu telemóvel ou computador.';
        }
    }
@endphp

<div
    x-data="{
        showPrompt: false,
        isProcessing: false,
        successState: false,
        iosInstalar: false,
        pedidoAdmin: {{ $pedidoAdminPendente ? 'true' : 'false' }},
        init() {
            const verificarEInicializar = () => {
                if (!window.mmcPush || typeof window.mmcPush.estado !== 'function') {
                    // Tenta novamente em 100ms caso o script push.js ainda não tenha carregado
                    setTimeout(verificarEInicializar, 100);
                    return;
                }

                // Um pedido explícito do administrador ignora o 'lembrar mais
                // tarde' — só deixa de aparecer quando o utilizador ativar.
                if (!this.pedidoAdmin) {
                    const dismissedUntil = localStorage.getItem('mmc_push_prompt_dismissed_until');
                    if (dismissedUntil && Date.now() < parseInt(dismissedUntil, 10)) {
                        return;
                    }
                }

                // 'default': ainda não ativou nem negou. 'ios-instalar': iOS Safari
                // fora do ecrã principal — mostra-se também, mas com instruções em
                // vez do botão 'Ativar' (pedir permissão não funciona nesse estado).
                // No registo diario este cartao assenta em cima do botao de
                // gravar (medido: prompt 602..768, botao 664..704 em 390 px) e
                // engole-lhe o clique. E um convite, nao vale bloquear a tarefa
                // principal — aparece em qualquer outra pagina.
                if (window.location.pathname.includes('/daily-records/create')) {
                    return;
                }

                const estado = window.mmcPush.estado();
                if (estado === 'default' || estado === 'ios-instalar' || this.pedidoAdmin) {
                    this.iosInstalar = estado === 'ios-instalar';
                    setTimeout(() => {
                        this.showPrompt = true;
                    }, 2000); // 2 segundos de delay após carregar a app
                }
            };

            verificarEInicializar();
        },
        async ativar() {
            this.isProcessing = true;
            try {
                const r = await window.mmcPush.ativar();
                if (r && r.ok && r.estado === 'granted') {
                    this.successState = true;
                    setTimeout(() => {
                        this.showPrompt = false;
                    }, 2500);
                } else {
                    // Se o utilizador negou ou fechou sem aceitar, fechamos o alerta e lembramos em 3 dias
                    this.lembrarMaisTarde();
                }
            } catch (error) {
                console.error('Erro ao ativar notificações no prompt:', error);
                this.showPrompt = false;
            } finally {
                this.isProcessing = false;
            }
        },
        lembrarMaisTarde() {
            // Um pedido do admin não se pode adiar por 3 dias — só fecha esta
            // sessão; volta a aparecer no próximo carregamento até ativar.
            if (!this.pedidoAdmin) {
                const threeDaysInMs = 3 * 24 * 60 * 60 * 1000;
                localStorage.setItem('mmc_push_prompt_dismissed_until', (Date.now() + threeDaysInMs).toString());
            }
            this.showPrompt = false;
        }
    }"
    x-show="showPrompt"
    x-transition:enter="transition ease-out duration-500"
    x-transition:enter-start="opacity-0 translate-y-8 md:translate-x-8 md:translate-y-0"
    x-transition:enter-end="opacity-100 translate-y-0 md:translate-x-0"
    x-transition:leave="transition ease-in duration-300"
    x-transition:leave-start="opacity-100 translate-y-0 md:translate-x-0"
    x-transition:leave-end="opacity-0 translate-y-8 md:translate-x-8 md:translate-y-0"
    x-cloak
    {{-- A barra dos timers de retrolavagem tambem e um overlay fixo no fundo:
         sem se empilhar com o espaco que ela ocupa, este cartao assentava por
         cima e engolia-lhe os cliques. --}}
    style="bottom: max(var(--mmc-prompt-bottom, 76px), var(--mmc-timer-bar-space, 0px))"
    class="fixed z-40 left-4 right-4 md:left-auto md:right-6 md:w-96 md:[--mmc-prompt-bottom:1.5rem] rounded-2xl p-5 shadow-2xl backdrop-blur-lg bg-white/95 dark:bg-gray-900/95 border border-gray-100 dark:border-gray-800 ring-1 ring-gray-950/5 dark:ring-white/10 flex flex-col gap-4"
>
    <!-- Animação do Sino e Conteúdo -->
    <div class="flex items-start gap-4">
        <div class="p-3 bg-primary-50 dark:bg-primary-950/50 text-primary-600 dark:text-primary-400 rounded-xl shrink-0">
            <!-- Ícone de sino com animação de balanço em CSS -->
            <svg class="h-6 w-6 animate-mmc-bell" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
            </svg>
        </div>
        <div class="space-y-1">
            <template x-if="!successState && !iosInstalar">
                <div>
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white" x-text="pedidoAdmin ? 'O administrador pediu que ative as notificações' : 'Ative as Notificações'"></h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                        {{ $textoPrompt }}
                    </p>
                </div>
            </template>
            <template x-if="!successState && iosInstalar">
                <div>
                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Instale a app para receber notificações</h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                        No Safari, toque em <strong>Partilhar</strong> e depois em <strong>Adicionar ao Ecrã Principal</strong>. O iPhone só permite notificações a apps instaladas assim.
                    </p>
                </div>
            </template>
            <template x-if="successState">
                <div>
                    <h4 class="text-sm font-semibold text-success-600 dark:text-success-400 flex items-center gap-1.5 animate-pulse">
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        Notificações Ativas!
                    </h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                        Excelente! Este dispositivo está agora pronto para receber os avisos importantes.
                    </p>
                </div>
            </template>
        </div>
    </div>

    <!-- Ações (Apenas mostradas se não for estado de sucesso) -->
    <template x-if="!successState">
        <div class="flex items-center justify-end gap-3 pt-1">
            <button
                type="button"
                x-on:click="lembrarMaisTarde()"
                class="px-3 py-2 text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 transition-colors cursor-pointer"
            >
                Lembrar mais tarde
            </button>
            <button
                type="button"
                x-show="!iosInstalar"
                x-on:click="ativar()"
                x-bind:disabled="isProcessing"
                class="px-4 py-2 bg-primary-600 hover:bg-primary-500 dark:bg-primary-500 dark:hover:bg-primary-400 text-white rounded-lg text-xs font-medium shadow-sm transition-all duration-200 active:scale-95 flex items-center gap-1.5 disabled:opacity-50 cursor-pointer"
            >
                <span x-text="isProcessing ? 'A ativar...' : 'Ativar'"></span>
            </button>
        </div>
    </template>
</div>

<style>
    /* Estilos e animação do sino */
    .animate-mmc-bell {
        animation: mmc-bell-ring 4s infinite ease-in-out;
        transform-origin: top center;
    }
    
    @keyframes mmc-bell-ring {
        0%, 100% { transform: rotate(0); }
        4% { transform: rotate(15deg); }
        8% { transform: rotate(-12deg); }
        12% { transform: rotate(10deg); }
        16% { transform: rotate(-8deg); }
        20% { transform: rotate(6deg); }
        24% { transform: rotate(-4deg); }
        28% { transform: rotate(2deg); }
        32%, 90% { transform: rotate(0); }
    }
</style>
