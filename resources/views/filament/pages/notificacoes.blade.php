<x-filament-panels::page>
    <div
        x-data="{
            estado: 'a-verificar',
            aProcessar: false,
            init() {
                this.estado = window.mmcPush ? window.mmcPush.estado() : 'nao-suportado';
            },
            async ativar() {
                this.aProcessar = true;
                const r = await window.mmcPush.ativar();
                this.estado = r.estado;
                this.aProcessar = false;
            },
        }"
        class="fi-section rounded-xl bg-white dark:bg-gray-900 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-6 max-w-2xl space-y-4"
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

        <template x-if="estado === 'default'">
            <div>
                <x-filament::button x-on:click="ativar()" x-bind:disabled="aProcessar" icon="heroicon-m-bell">
                    <span x-text="aProcessar ? 'A ativar...' : 'Ativar notificações'"></span>
                </x-filament::button>
            </div>
        </template>

        @if($this->podeTestar())
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-3" x-show="estado === 'granted'">
                <div>
                    <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Zona de testes</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Cada botão agenda um push de teste para chegar daqui a ~5 segundos — bloqueia o ecrã depois de clicar.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
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
                    @foreach($this->tiposDeTeste() as $tipo)
                        <x-filament::button
                            color="gray"
                            size="sm"
                            wire:click="testar('{{ $tipo }}')"
                        >
                            {{ $rotulos[$tipo] ?? $tipo }}
                        </x-filament::button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
