<x-filament-panels::page>
    <div
        x-data="{
            estado: 'a-verificar',
            aProcessar: false,
            erroMsg: '',
            init() {
                this.estado = window.mmcPush ? window.mmcPush.estado() : 'nao-suportado';
            },
            async ativar() {
                this.aProcessar = true;
                this.erroMsg = '';
                const r = await window.mmcPush.ativar();
                this.estado = r.estado;
                if (!r.ok && r.estado === 'erro-subscricao') {
                    this.erroMsg = r.erroMsg || 'Erro desconhecido ao registar no serviço de push.';
                }
                this.aProcessar = false;
            },
        }"
        class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 max-w-2xl space-y-4 mb-6"
    >
        <div class="flex items-start gap-3">
            <x-filament::icon icon="heroicon-o-bell-alert" class="h-6 w-6 text-primary-500 shrink-0 mt-0.5" />
            <div>
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">Notificações neste dispositivo</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Receba avisos de novos incidentes e do fim do timer de retrolavagem, mesmo com a app fechada.
                </p>
            </div>
        </div>

        <template x-if="estado === 'granted'">
            <div class="rounded-lg bg-success-50 dark:bg-success-950 border border-success-200 dark:border-success-800 p-4 text-sm text-success-700 dark:text-success-400">
                Notificações ativas neste dispositivo.
            </div>
        </template>

        <template x-if="estado === 'ios-instalar'">
            <div class="rounded-lg bg-warning-50 dark:bg-warning-950 border border-warning-200 dark:border-warning-800 p-4 text-sm text-warning-700 dark:text-warning-400 space-y-2">
                <p class="font-medium">No iPhone/iPad é preciso instalar a app primeiro:</p>
                <ol class="list-decimal list-inside space-y-1">
                    <li>Abra este site no Safari.</li>
                    <li>Toque no botão Partilhar (quadrado com seta).</li>
                    <li>Escolha "Adicionar ao ecrã principal".</li>
                    <li>Abra a app a partir do ícone no ecrã e volte a esta página.</li>
                </ol>
            </div>
        </template>

        <template x-if="estado === 'denied'">
            <div class="rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 p-4 text-sm text-danger-700 dark:text-danger-400">
                As notificações foram bloqueadas. Ative-as nas definições do navegador/telemóvel para este site e recarregue a página.
            </div>
        </template>

        <template x-if="estado === 'nao-suportado'">
            <div class="rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4 text-sm text-gray-600 dark:text-gray-300">
                Este navegador não suporta notificações push.
            </div>
        </template>

        <template x-if="estado === 'sem-vapid'">
            <div class="rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 p-4 text-sm text-danger-700 dark:text-danger-400">
                As chaves de notificação (VAPID) não estão configuradas no servidor. Contacte o administrador.
            </div>
        </template>

        <template x-if="estado === 'erro-subscricao'">
            <div class="rounded-lg bg-danger-50 dark:bg-danger-950 border border-danger-200 dark:border-danger-800 p-4 text-sm text-danger-700 dark:text-danger-400 space-y-2">
                <p class="font-semibold text-base">Falha ao registar o dispositivo no serviço de push:</p>
                <p class="text-xs font-mono bg-white/10 p-2 rounded" x-text="erroMsg"></p>
                <p class="text-xs mt-2">
                    <strong>Dicas de resolução:</strong>
                </p>
                <ul class="list-disc list-inside text-xs space-y-1">
                    <li>Se usa o <strong>Brave Browser</strong>, ative <em>"Use Google services for push messaging"</em> em Definições &gt; Privacidade.</li>
                    <li>Experimente limpar os cookies e dados do site (no Chrome: cadeado ao lado do link &gt; Definições de Sites &gt; Limpar dados) e recarregue a página.</li>
                    <li>Garanta que as chaves <code>VAPID_PUBLIC_KEY</code> e <code>VAPID_PRIVATE_KEY</code> no <code>.env</code> do servidor estão corretamente configuradas (sem aspas duplicadas ou espaços adicionais).</li>
                </ul>
                <div class="pt-2">
                    <x-filament::button size="xs" color="danger" x-on:click="estado = 'default'">
                        Tentar novamente
                    </x-filament::button>
                </div>
            </div>
        </template>

        <template x-if="estado === 'default'">
            <div>
                <x-filament::button x-on:click="ativar()" x-bind:disabled="aProcessar" icon="heroicon-m-bell">
                    <span x-text="aProcessar ? 'A ativar...' : 'Ativar notificações'"></span>
                </x-filament::button>
            </div>
        </template>
    </div>

    @if($this->podeGerir())
        <div class="space-y-6">
            {{-- Tabela de Custom Broadcasts (Avisos Personalizados) --}}
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white mb-4">Gestão de Avisos</h2>
                {{ $this->table }}
            </div>

            {{-- Zona de Testes --}}
            <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 max-w-2xl mt-6">
                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Zona de Testes</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Simular a receção de notificações do sistema no dispositivo atual.
                            (Bloqueia o ecrã depois de clicar para testar no ecrã de bloqueio).
                        </p>
                    </div>

                    @php
                        $rotulos = [
                            'incidente' => 'Novo incidente',
                            'mensagem' => 'Mensagem de incidente',
                            'timer' => 'Timer terminado',
                            'fora_limites' => 'Fora dos limites',
                            'torneira' => 'Torneira aberta',
                            'resumo' => 'Resumo de conformidade',
                        ];
                    @endphp

                    <div class="space-y-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Tipo</label>
                            <select
                                wire:model.live="tipoSelecionado"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-gray-950 dark:text-white focus:border-primary-500 focus:ring-primary-500"
                            >
                                @foreach($this->tiposDeTeste() as $tipo)
                                    <option value="{{ $tipo }}">{{ $rotulos[$tipo] ?? $tipo }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Título</label>
                            <input
                                type="text"
                                wire:model="tituloTeste"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-gray-950 dark:text-white focus:border-primary-500 focus:ring-primary-500"
                            />
                        </div>

                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Mensagem</label>
                            <textarea
                                wire:model="corpoTeste"
                                rows="2"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-gray-950 dark:text-white focus:border-primary-500 focus:ring-primary-500"
                            ></textarea>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-filament::button color="primary" size="sm" wire:click="testarImediato" icon="heroicon-m-bolt">
                                Enviar Imediato (Testar Chaves/Permissões)
                            </x-filament::button>
                            <x-filament::button color="gray" size="sm" wire:click="testar" icon="heroicon-m-paper-airplane">
                                Enviar c/ Atraso 5s (Testar ecrã bloqueado)
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Diagnóstico do Agendador --}}
            <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 max-w-2xl mt-6 space-y-4">
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-2">Diagnóstico do Agendador (Scheduler)</h3>
                    <pre class="text-xs font-mono bg-gray-50 dark:bg-gray-800 p-4 rounded overflow-auto max-h-60 text-gray-800 dark:text-gray-200">{{ $this->getSchedulerStatus() }}</pre>
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white mb-2">Base de Dados (Pushes Pendentes)</h3>
                    <pre class="text-xs font-mono bg-gray-50 dark:bg-gray-800 p-4 rounded overflow-auto max-h-60 text-gray-800 dark:text-gray-200">{{ $this->getPendingPushesDebug() }}</pre>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
