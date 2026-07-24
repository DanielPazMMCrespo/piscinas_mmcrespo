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

    <form wire:submit="savePreferences" class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 max-w-2xl space-y-6 mb-6">
        {{ $this->preferencesForm }}
        
        <div class="flex justify-end">
            <x-filament::button type="submit" size="sm">
                Guardar Preferências
            </x-filament::button>
        </div>
    </form>

    @if($this->podeGerir())
        <div class="space-y-6">
            {{-- Tabela de Custom Broadcasts (Avisos Personalizados) --}}
            <div>
                <h2 class="text-lg font-semibold text-gray-950 dark:text-white mb-4">Gestão de Avisos</h2>
                {{ $this->table }}
            </div>

            {{-- Envio Manual --}}
            <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 max-w-2xl mt-6">
                <div class="space-y-3">
                    <div>
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Envio Manual</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Enviar uma notificação push imediata para um cargo específico ou para um utilizador individual.
                        </p>
                    </div>

                    <div class="space-y-4 pt-4 border-t border-gray-200 dark:border-gray-700 mt-4">
                        <div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Destinatários</span>
                            <div class="mt-2 flex gap-6">
                                <label for="destinoTipo_cargo" class="inline-flex items-center gap-2 text-sm text-gray-950 dark:text-white cursor-pointer">
                                    <input type="radio" id="destinoTipo_cargo" name="destinoTipo" wire:model.live="destinoTipo" value="cargo" class="text-primary-600 focus:ring-primary-500 dark:bg-gray-800 dark:border-gray-700" />
                                    Por Cargo
                                </label>
                                <label for="destinoTipo_utilizador" class="inline-flex items-center gap-2 text-sm text-gray-950 dark:text-white cursor-pointer">
                                    <input type="radio" id="destinoTipo_utilizador" name="destinoTipo" wire:model.live="destinoTipo" value="utilizador" class="text-primary-600 focus:ring-primary-500 dark:bg-gray-800 dark:border-gray-700" />
                                    Por Utilizador Específico
                                </label>
                            </div>
                        </div>

                        @if($destinoTipo === 'cargo')
                            <div>
                                <label for="destinoCargo" class="text-sm font-medium text-gray-700 dark:text-gray-300">Selecionar Cargo</label>
                                <select
                                    id="destinoCargo"
                                    name="destinoCargo"
                                    wire:model="destinoCargo"
                                    class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-gray-950 dark:text-white focus:border-primary-500 focus:ring-primary-500"
                                >
                                    @foreach(self::rotulosCargos() as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <div>
                                <label for="destinoUtilizador" class="text-sm font-medium text-gray-700 dark:text-gray-300">Selecionar Utilizador</label>
                                <select
                                    id="destinoUtilizador"
                                    name="destinoUtilizador"
                                    wire:model="destinoUtilizador"
                                    class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-gray-950 dark:text-white focus:border-primary-500 focus:ring-primary-500"
                                >
                                    <option value="">Selecione um utilizador...</option>
                                    @foreach($this->getUsuariosLista() as $id => $nome)
                                        <option value="{{ $id }}">{{ $nome }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div>
                            <label for="manualTitulo" class="text-sm font-medium text-gray-700 dark:text-gray-300">Título</label>
                            <input
                                type="text"
                                id="manualTitulo"
                                name="manualTitulo"
                                wire:model="manualTitulo"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-gray-950 dark:text-white focus:border-primary-500 focus:ring-primary-500"
                                placeholder="Título da notificação..."
                            />
                        </div>

                        <div>
                            <label for="manualCorpo" class="text-sm font-medium text-gray-700 dark:text-gray-300">Mensagem</label>
                            <textarea
                                id="manualCorpo"
                                name="manualCorpo"
                                wire:model="manualCorpo"
                                rows="3"
                                class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 text-sm text-gray-950 dark:text-white focus:border-primary-500 focus:ring-primary-500"
                                placeholder="Escreva a mensagem aqui..."
                            ></textarea>
                        </div>

                        <div class="flex">
                            <x-filament::button color="primary" size="sm" wire:click="enviarManual" icon="heroicon-m-paper-airplane">
                                Enviar Notificação Push
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Estado das Notificações dos Utilizadores --}}
            <div class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 mt-6">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white mb-4">Estado das Notificações dos Utilizadores</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-800">
                                <th class="py-2 pb-3 font-semibold text-gray-700 dark:text-gray-300 w-1/3">Nome</th>
                                <th class="py-2 pb-3 font-semibold text-gray-700 dark:text-gray-300 w-1/3">Cargos</th>
                                <th class="py-2 pb-3 font-semibold text-gray-700 dark:text-gray-300 w-1/3">Dispositivos Ativos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach($this->getUsuariosNotificacoes() as $usuario)
                                <tr>
                                    <td class="py-3 text-gray-900 dark:text-gray-100 font-medium">{{ $usuario->name }}</td>
                                    <td class="py-3 text-gray-500 dark:text-gray-400">
                                        {{ collect($usuario->roles)->pluck('name')->map(fn($r) => self::rotulosCargos()[$r] ?? $r)->join(', ') ?: 'Nenhum' }}
                                    </td>
                                    <td class="py-3">
                                        @if($usuario->push_subscriptions_count > 0)
                                            <span class="inline-flex items-center gap-1.5 rounded-md bg-success-50 dark:bg-success-950 px-2 py-1 text-xs font-medium text-success-700 dark:text-success-300 ring-1 ring-inset ring-success-600/10 dark:ring-success-500/20">
                                                <span class="h-1.5 w-1.5 rounded-full bg-success-500"></span>
                                                {{ $usuario->push_subscriptions_count }} {{ $usuario->push_subscriptions_count === 1 ? 'dispositivo' : 'dispositivos' }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-50 dark:bg-gray-800 px-2 py-1 text-xs font-medium text-gray-600 dark:text-gray-400 ring-1 ring-inset ring-gray-500/10">
                                                <span class="h-1.5 w-1.5 rounded-full bg-gray-400"></span>
                                                Inativo (0)
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
</x-filament-panels::page>
