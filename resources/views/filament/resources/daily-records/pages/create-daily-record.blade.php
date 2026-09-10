<x-filament-panels::page>
    {{-- Banner de Estado de Rede e Rascunho Offline (Apple HIG / Tesla Field UX) --}}
    <div
        x-data="{
            online: navigator.onLine,
            temRascunho: false,
            dataRascunho: '',
            chaveRascunho: 'mmcrespo_draft_daily_record_' + ({{ auth()->id() ?? 0 }}),
            init() {
                window.addEventListener('online', () => { this.online = true; });
                window.addEventListener('offline', () => { this.online = false; });
                this.verificarRascunho();

                // Escuta alterações nos inputs do form para auto-gravar rascunho
                const formEl = document.querySelector('form.fi-form');
                if (formEl) {
                    formEl.addEventListener('input', () => this.guardarRascunho());
                    formEl.addEventListener('change', () => this.guardarRascunho());
                }

                // Limpa o rascunho após submissão com sucesso
                window.addEventListener('dailyRecordSaved', () => {
                    this.limparRascunho();
                });
            },
            verificarRascunho() {
                const salvo = localStorage.getItem(this.chaveRascunho);
                if (salvo) {
                    try {
                        const parsed = JSON.parse(salvo);
                        if (parsed && parsed.campos && Object.keys(parsed.campos).length > 0) {
                            this.temRascunho = true;
                            this.dataRascunho = parsed.hora || '';
                        }
                    } catch (e) {}
                }
            },
            guardarRascunho() {
                const formEl = document.querySelector('form.fi-form');
                if (!formEl) return;
                const inputs = formEl.querySelectorAll('input, select, textarea');
                const campos = {};
                let count = 0;
                inputs.forEach(el => {
                    if (el.name && el.value !== '' && !el.name.startsWith('_')) {
                        campos[el.name] = el.value;
                        count++;
                    }
                });

                if (count === 0) return;

                const now = new Date();
                const horaStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                localStorage.setItem(this.chaveRascunho, JSON.stringify({
                    campos: campos,
                    hora: horaStr,
                    timestamp: now.getTime()
                }));
                this.temRascunho = true;
                this.dataRascunho = horaStr;
            },
            restaurarRascunho() {
                const salvo = localStorage.getItem(this.chaveRascunho);
                if (!salvo) return;
                try {
                    const parsed = JSON.parse(salvo);
                    if (parsed && parsed.campos) {
                        const formEl = document.querySelector('form.fi-form');
                        for (let k in parsed.campos) {
                            const input = formEl?.querySelector(`[name='${k}']`);
                            if (input) {
                                input.value = parsed.campos[k];
                                input.dispatchEvent(new Event('input', { bubbles: true }));
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                            }
                        }
                    }
                } catch (e) {}
            },
            limparRascunho() {
                localStorage.removeItem(this.chaveRascunho);
                this.temRascunho = false;
                this.dataRascunho = '';
            }
        }"
        class="mb-6 space-y-3"
    >
        {{-- Alerta quando offline na casa das máquinas --}}
        <template x-if="!online">
            <div class="flex items-center justify-between gap-3 p-4 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-700 dark:text-amber-400 text-sm shadow-sm">
                <div class="flex items-center gap-2.5">
                    <x-filament::icon icon="heroicon-o-signal-slash" class="h-5 w-5 text-amber-500 shrink-0" />
                    <span><strong>Modo sem rede (cave):</strong> Os valores que preencher estão salvaguardados no dispositivo. Ao subir à superfície e recuperar rede, clique em <em>Gravar Registos</em>.</span>
                </div>
            </div>
        </template>

        {{-- Alerta quando online e com rascunho recuperável --}}
        <template x-if="online && temRascunho">
            <div class="flex items-center justify-between gap-3 p-3.5 rounded-xl bg-primary-500/10 border border-primary-500/20 text-primary-700 dark:text-primary-300 text-sm shadow-sm">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-document-check" class="h-5 w-5 text-primary-500 shrink-0" />
                    <span>Rascunho guardado localmente às <strong x-text="dataRascunho"></strong>.</span>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        x-on:click="restaurarRascunho()"
                        class="px-2.5 py-1 rounded-lg bg-primary-600 hover:bg-primary-700 text-white text-xs font-medium shadow-sm transition"
                    >
                        Restaurar Valores
                    </button>
                    <button
                        type="button"
                        x-on:click="limparRascunho()"
                        class="px-2.5 py-1 rounded-lg bg-gray-200 dark:bg-gray-800 hover:bg-gray-300 text-gray-700 dark:text-gray-300 text-xs font-medium transition"
                    >
                        Descartar
                    </button>
                </div>
            </div>
        </template>
    </div>

    <x-filament-panels::form wire:submit="create">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="$this->getCachedFormActions()"
            :full-width="$this->hasFullWidthFormActions()"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
