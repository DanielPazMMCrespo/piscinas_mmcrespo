import './bootstrap';
import { registarMmcEcharts } from './charts/analise.js';


const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Shared Chart.js class (with plugins) across all mmcChart instances on the page.
// Avoids duplicate plugin registration and duplicate dynamic imports.
let ChartWithPlugins = null;

/**
 * Componente Alpine para os gráficos de parâmetros (dual Y-axis, zoom/pan, time scale).
 *
 * Recebe um payload com a estrutura:
 *   { titulo, period, left: { key, label, unidade, casas, yMin, yMax, cor, banda, datasets },
 *                     right: { ... } }
 *
 * Plugins carregados dinamicamente (code splitting):
 *   - chartjs-plugin-zoom  (scroll zoom + drag pan + pinch mobile)
 *   - chartjs-plugin-annotation  (bandas de conformidade CN 14/DA como box annotations)
 *   - chartjs-adapter-luxon  (time scale com timestamps ISO)
 *   - hammerjs  (peer dep do zoom plugin para pinch/touch)
 */
document.addEventListener('alpine:init', () => {
    registarMmcEcharts(window.Alpine);
    window.Alpine.data('mmcChart', (initialPayload = null) => ({
        chart: null,
        resizeObserver: null,
        resizeTimer: null,
        _destroyed: false,
        _rafId: null,
        _offChartUpdate: null,
        _payload: initialPayload,
        _hasData: !!(initialPayload?.left && initialPayload?.right),
        _renderRetries: 0,

        get _hasSeries() {
            const p = this._payload;
            if (!p || !p.left || !p.right) return false;
            const hasPoints = (axis) => (axis.datasets || []).some((ds) => (ds.data || []).length > 0);
            return hasPoints(p.left) || hasPoints(p.right);
        },

        async init() {
            if (!ChartWithPlugins) {
                // hammerjs precisa de estar em window.Hammer antes do zoom plugin tratar eventos táteis
                const hammerMod = await import('hammerjs');
                window.Hammer = hammerMod.default ?? hammerMod;

                const [chartJs, zoomMod, annotMod] = await Promise.all([
                    import('chart.js'),
                    import('chartjs-plugin-zoom'),
                    import('chartjs-plugin-annotation'),
                ]);
                await import('chartjs-adapter-luxon');

                ChartWithPlugins = chartJs.Chart;
                ChartWithPlugins.register(
                    chartJs.LineController,
                    chartJs.LineElement,
                    chartJs.PointElement,
                    chartJs.LinearScale,
                    chartJs.TimeScale,
                    chartJs.Filler,
                    chartJs.Legend,
                    chartJs.Tooltip,
                    zoomMod.default,
                    annotMod.default,
                );
            }

            // Render inicial: usa os dados passados via x-data="mmcChart({...})"
            if (this._hasData) {
                this.$nextTick(() => {
                    if (!this._destroyed) this.render();
                });
            }

            // Escuta eventos Livewire para atualizar o gráfico quando a piscina/métrica/período muda.
            // O componente Alpine fica vivo (wire:ignore) e recebe dados frescos via este canal,
            // em vez de depender do DOM morph do Livewire (que é a causa do bug).
            if (typeof Livewire !== 'undefined') {
                this._offChartUpdate = Livewire.on('mmc-chart-update', (eventData) => {
                    if (this._destroyed) return;
                    const payload = eventData?.payload ?? eventData;
                    if (!payload) return;

                    this._payload = payload;
                    const hadData = this._hasData;
                    this._hasData = !!(payload?.left && payload?.right);

                    if (this._hasData) {
                        // $nextTick garante que x-show já processou _hasData=true
                        // e o canvas está visível antes de renderizar
                        this.$nextTick(() => {
                            if (!this._destroyed) this.render();
                        });
                    } else if (this.chart) {
                        this.chart.destroy();
                        this.chart = null;
                    }
                });
            }

            // Reage a mudanças de dark mode — skip na primeira execução (já renderizámos acima)
            let themeEffectFirst = true;
            Alpine.effect(() => {
                Alpine.store('theme');
                if (themeEffectFirst) { themeEffectFirst = false; return; }
                if (this._hasData) {
                    this.$nextTick(() => { if (!this._destroyed) this.render(); });
                }
            });

            this.resizeObserver = new ResizeObserver(() => {
                clearTimeout(this.resizeTimer);
                this.resizeTimer = setTimeout(() => { if (!this._destroyed) this.chart?.resize(); }, 100);
            });
            this.resizeObserver.observe(this.$el);
        },

        destroy() {
            this._destroyed = true;
            if (this._rafId) {
                cancelAnimationFrame(this._rafId);
                this._rafId = null;
            }
            if (this._offChartUpdate) {
                this._offChartUpdate();
                this._offChartUpdate = null;
            }
            this.resizeObserver?.disconnect();
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }
            const canvas = this.$refs.canvas;
            if (canvas && ChartWithPlugins) {
                const existing = ChartWithPlugins.getChart(canvas);
                if (existing) {
                    existing.destroy();
                }
            }
        },

        resetZoom() {
            this.chart?.resetZoom();
        },

        cores() {
            const escuro = document.documentElement.classList.contains('dark');
            return {
                texto:  escuro ? 'rgba(255,255,255,0.65)' : 'rgba(0,0,0,0.6)',
                grelha: escuro ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)',
                banda:  escuro ? 'rgba(118,184,42,0.12)'  : 'rgba(118,184,42,0.14)',
            };
        },

        buildAnnotations(left, right) {
            const c = this.cores();
            const ann = {};
            if (left?.banda) {
                ann.bandaLeft = {
                    type: 'box', yScaleID: 'y',
                    yMin: left.banda.min, yMax: left.banda.max,
                    backgroundColor: c.banda, borderWidth: 0,
                };
            }
            if (right?.banda) {
                ann.bandaRight = {
                    type: 'box', yScaleID: 'y1',
                    yMin: right.banda.min, yMax: right.banda.max,
                    backgroundColor: c.banda, borderWidth: 0,
                };
            }
            return ann;
        },

        buildDataset(ds, yAxisID, cor) {
            return {
                label: ds.label,
                data: ds.data,
                yAxisID,
                borderColor: cor,
                backgroundColor: cor,
                borderWidth: 2.5,
                // Mostrar pontos apenas quando há poucos (registos manuais ou curtos períodos)
                pointRadius: ds.data.length <= 60 ? 3 : 0,
                pointHoverRadius: 5,
                tension: 0.3,
                spanGaps: false,
                order: 1,
                ...(ds.dashed ? { borderDash: [5, 5] } : {}),
            };
        },

        render() {
            if (this._destroyed) return;
            const canvas = this.$refs.canvas;
            if (!canvas) return;

            // Aguardar que o canvas tenha dimensões (pode estar a transitar de x-show hidden para visible)
            if (canvas.offsetWidth === 0 || canvas.offsetHeight === 0) {
                if (this._renderRetries < 15) {
                    this._renderRetries++;
                    this._rafId = requestAnimationFrame(() => {
                        if (!this._destroyed) this.render();
                    });
                }
                return;
            }
            this._renderRetries = 0;

            // Limpar chart anterior (dupla verificação — por instância e por registo global do Chart.js)
            if (ChartWithPlugins) {
                const existing = ChartWithPlugins.getChart(canvas);
                if (existing) {
                    existing.destroy();
                }
            }
            if (this.chart) {
                this.chart.destroy();
                this.chart = null;
            }

            const activeConfig = this._payload;
            if (!activeConfig || !activeConfig.left || !activeConfig.right) return;

            const c = this.cores();
            const left = activeConfig.left;
            const right = activeConfig.right;
            const datasets = [];

            left.datasets.forEach((ds) => datasets.push(this.buildDataset(ds, 'y', left.cor)));
            right.datasets.forEach((ds) => datasets.push(this.buildDataset(ds, 'y1', right.cor)));

            const isShort = activeConfig.period === '6h' || activeConfig.period === '24h';
            const timeUnit = isShort ? 'hour' : 'day';

            this.chart = new ChartWithPlugins(canvas, {
                type: 'line',
                data: { datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                color: c.texto,
                                boxWidth: 10, boxHeight: 10,
                                usePointStyle: true, pointStyle: 'line',
                            },
                        },
                        tooltip: {
                            callbacks: {
                                label: (ctx) => {
                                    const axis = ctx.dataset.yAxisID === 'y' ? left : right;
                                    const u = axis.unidade ? ' ' + axis.unidade : '';
                                    return `${ctx.dataset.label}: ${ctx.formattedValue}${u}`;
                                },
                            },
                        },
                        zoom: {
                            zoom: {
                                wheel: { enabled: true, modifierKey: 'ctrl' },
                                pinch: { enabled: true },
                                mode: 'xy',
                            },
                            pan: {
                                enabled: true,
                                mode: 'xy',
                            },
                        },
                        annotation: {
                            annotations: this.buildAnnotations(left, right),
                        },
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: timeUnit,
                                displayFormats: {
                                    hour: 'HH:mm',
                                    day:  'dd/MM',
                                },
                                tooltipFormat: 'dd/MM/yyyy HH:mm',
                            },
                            grid: { display: false },
                            ticks: { color: c.texto, maxRotation: 0, autoSkipPadding: 16 },
                        },
                        y: {
                            type: 'linear',
                            position: 'left',
                            suggestedMin: left.yMin,
                            suggestedMax: left.yMax,
                            grid: { color: c.grelha },
                            ticks: { color: left.cor },
                            title: {
                                display: true,
                                text: left.unidade ? `${left.label} (${left.unidade})` : left.label,
                                color: left.cor,
                                font: { size: 11 },
                            },
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            suggestedMin: right.yMin,
                            suggestedMax: right.yMax,
                            grid: { drawOnChartArea: false },
                            ticks: { color: right.cor },
                            title: {
                                display: true,
                                text: right.unidade ? `${right.label} (${right.unidade})` : right.label,
                                color: right.cor,
                                font: { size: 11 },
                            },
                        },
                    },
                },
            });
        },
    }));

    /**
     * Quadro Kanban operacional: drag-and-drop entre colunas (SortableJS)
     * com persistência via Livewire (moverAlerta) e entrada animada (GSAP).
     */
    window.Alpine.data('mmcKanban', () => ({
        sortables: [],
        SortableClass: null,
        gsapObj: null,

        async init() {
            if (!this.SortableClass || !this.gsapObj) {
                const [sortableModule, gsapModule] = await Promise.all([
                    import('sortablejs'),
                    import('gsap')
                ]);
                this.SortableClass = sortableModule.default;
                this.gsapObj = gsapModule.gsap;
            }

            this.montar();

            // O Livewire substitui o DOM das listas após cada movimento/polling —
            // destrói e volta a montar o Sortable para não ficar órfão.
            Livewire.hook('morph.updated', ({ el }) => {
                if (el === this.$el || this.$el.contains(el)) {
                    clearTimeout(this._remount);
                    this._remount = setTimeout(() => this.montar(), 50);
                }
            });

            if (!reduzMovimento) {
                this.gsapObj.from(this.$el.querySelectorAll('.mmc-kb-card'), {
                    y: 14, opacity: 0, duration: 0.35, stagger: 0.05, ease: 'power2.out', clearProps: 'all',
                });
            }
        },

        montar() {
            this.sortables = this.sortables.filter((s) => {
                if (!document.body.contains(s.el)) {
                    s.destroy();
                    return false;
                }
                return true;
            });

            this.$el.querySelectorAll('.mmc-kb-list').forEach((lista) => {
                if (lista.dataset.sortableId) {
                    return;
                }

                const sortableId = 'sortable_' + Math.random().toString(36).substr(2, 9);
                lista.dataset.sortableId = sortableId;

                this.sortables.push(this.SortableClass.create(lista, {
                    group: 'mmc-kanban',
                    animation: 150,
                    ghostClass: 'mmc-kb-ghost',
                    dragClass: 'mmc-kb-drag',
                    // Nos ecrãs táteis o arrasto exige pressão longa para não
                    // lutar com o scroll horizontal das colunas.
                    delay: 150,
                    delayOnTouchOnly: true,
                    filter: '.mmc-kb-btn, a',
                    preventOnFilter: false,
                    onMove: (evt) => {
                        this.$el.querySelectorAll('.mmc-kb-col').forEach(col => col.classList.remove('mmc-kb-col--over'));
                        evt.to?.closest('.mmc-kb-col')?.classList.add('mmc-kb-col--over');
                        return true;
                    },
                    onEnd: () => {
                        this.$el.querySelectorAll('.mmc-kb-col').forEach(col => col.classList.remove('mmc-kb-col--over'));
                    },
                    onAdd: (evt) => {
                        const key = evt.item?.dataset?.key;
                        const status = evt.to?.dataset?.status;
                        if (key && status) {
                            if (!reduzMovimento) {
                                this.gsapObj.from(evt.item, { scale: 0.96, duration: 0.2, ease: 'power2.out', clearProps: 'all' });
                            }
                            this.$wire.moverAlerta(key, status);
                        }
                    },
                }));
            });
        },

        destroy() {
            this.sortables.forEach((s) => s.destroy());
        },
    }));

    window.Alpine.data('countdownTimer', (statePath, defaultSeconds = 180) => ({
        statePath: statePath,
        initialSeconds: defaultSeconds,
        remainingSeconds: defaultSeconds,
        timer: null,
        isRunning: false,

        get formattedTime() {
            const isNeg = this.remainingSeconds < 0;
            const absSecs = Math.abs(this.remainingSeconds);
            const m = Math.floor(absSecs / 60).toString().padStart(2, '0');
            const s = (absSecs % 60).toString().padStart(2, '0');
            return `${isNeg ? '-' : ''}${m}:${s}`;
        },
        
        get isExceeded() {
            return this.remainingSeconds < 0;
        },

        init() {
            const storageKey = 'mmc_timer_' + this.statePath;
            const saved = localStorage.getItem(storageKey);
            
            if (saved) {
                try {
                    const data = JSON.parse(saved);
                    this.initialSeconds = data.initialSeconds ?? defaultSeconds;
                    this.isRunning = data.isRunning ?? false;
                    
                    if (this.isRunning && data.endTime) {
                        const remaining = Math.round((data.endTime - Date.now()) / 1000);
                        this.remainingSeconds = remaining;
                        this.startTimer();
                    } else {
                        this.remainingSeconds = data.remainingSeconds ?? defaultSeconds;
                    }
                } catch (e) {
                    console.error('Error loading timer:', e);
                }
            } else {
                this.remainingSeconds = defaultSeconds;
            }

            // Auto-save on any change
            this.$watch('remainingSeconds', () => this.saveState());
            this.$watch('initialSeconds', () => this.saveState());
            this.$watch('isRunning', () => this.saveState());
        },

        saveState() {
            const storageKey = 'mmc_timer_' + this.statePath;
            const data = {
                initialSeconds: this.initialSeconds,
                remainingSeconds: this.remainingSeconds,
                isRunning: this.isRunning
            };
            if (this.isRunning) {
                data.endTime = Date.now() + (this.remainingSeconds * 1000);
            }
            localStorage.setItem(storageKey, JSON.stringify(data));
        },

        toggleTimer() {
            if (this.isRunning) {
                this.pauseTimer();
            } else {
                this.startTimer();
            }
        },

        startTimer() {
            if (this.isRunning && this.timer) return;
            this.isRunning = true;
            this.timer = setInterval(() => {
                this.remainingSeconds--;
            }, 1000);
        },

        pauseTimer() {
            this.isRunning = false;
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
        },
        
        adjustTime(seconds) {
            this.initialSeconds += seconds;
            if (this.initialSeconds < 60) this.initialSeconds = 60;
            if (!this.isRunning && this.remainingSeconds > 0) {
                this.remainingSeconds = this.initialSeconds;
            }
        },

        destroy() {
            if (this.timer) {
                clearInterval(this.timer);
            }
        }
    }));
});

// Conversão e sanitização de vírgula para ponto em campos decimais.
// Delegado no document (capture) para cobrir inputs do Livewire e modais do Filament.
const setupDecimalInputs = () => {
    const isDecimalEl = (el) =>
        el.tagName === 'INPUT' &&
        (el.getAttribute('inputmode') === 'decimal' || el.type === 'number');

    // Evento input -> converte vírgulas em pontos e sanitiza o texto digitado (apenas números e no máximo um ponto)
    document.addEventListener('input', (e) => {
        const el = e.target;
        if (!isDecimalEl(el) || el.type !== 'text') return;

        const start = el.selectionStart ?? 0;
        const originalValue = el.value;

        // Substituir qualquer vírgula por ponto
        let newValue = originalValue.replace(/,/g, '.');

        // Remover qualquer caracter que não seja número ou ponto
        newValue = newValue.replace(/[^0-9.]/g, '');

        // Garantir que existe no máximo um ponto
        const parts = newValue.split('.');
        if (parts.length > 2) {
            newValue = parts[0] + '.' + parts.slice(1).join('');
        }

        if (originalValue !== newValue) {
            el.value = newValue;
            const diff = originalValue.length - newValue.length;
            const newPos = Math.max(0, start - diff);
            el.setSelectionRange(newPos, newPos);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, { capture: true });

    // Colar texto -> converte vírgulas para pontos e sanitiza
    document.addEventListener('paste', (e) => {
        const el = e.target;
        if (!isDecimalEl(el) || el.type !== 'text') return;

        const texto = (e.clipboardData ?? window.clipboardData)?.getData('text') ?? '';
        if (!texto) return;

        e.preventDefault();
        const corrigido = texto.replace(/,/g, '.').replace(/[^0-9.]/g, '');

        const start = el.selectionStart ?? 0;
        const end = el.selectionEnd ?? 0;
        const val = el.value;

        let newValue = val.slice(0, start) + corrigido + val.slice(end);

        // Garantir no máximo um ponto
        const parts = newValue.split('.');
        if (parts.length > 2) {
            newValue = parts[0] + '.' + parts.slice(1).join('');
        }

        el.value = newValue;
        const newPos = start + corrigido.length;
        el.setSelectionRange(newPos, newPos);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }, { capture: true, passive: false });
};

// Logótipo e layout do header: reorganizar quando a barra lateral recolhe
const setupHeaderLayout = () => {
    const sidebarMain = document.querySelector('aside[class*="sidebar"]');
    const navbar = document.querySelector('nav');
    if (!sidebarMain || !navbar) return;

    let headerLogo = null;

    const updateHeaderLayout = () => {
        const isHidden = sidebarMain.offsetWidth < 120 ||
                         sidebarMain.style.display === 'none' ||
                         getComputedStyle(sidebarMain).display === 'none';

        if (isHidden) {
            // Barra lateral recolhida: criar/mostrar logótipo no header
            if (!headerLogo) {
                // Criar contentor para o logótipo apenas
                headerLogo = document.createElement('div');
                headerLogo.id = 'mmcrespo-header-logo';
                headerLogo.style.cssText = `
                    display: flex !important;
                    align-items: center !important;
                    gap: 0.25rem !important;
                    margin-right: auto !important;
                `;

                // Clonar apenas as imagens do logótipo
                const brandLink = document.querySelector('.fi-sidebar-header a');
                if (brandLink) {
                    const brandImages = brandLink.querySelectorAll('img');
                    brandImages.forEach((img) => {
                        const imgClone = img.cloneNode(true);
                        imgClone.style.height = '2.5rem';
                        imgClone.style.width = 'auto';
                        imgClone.style.display = 'block';
                        headerLogo.appendChild(imgClone);
                    });
                }

                // Garantir que o navbar é flex
                navbar.style.display = 'flex';
                navbar.style.alignItems = 'center';

                // Inserir no início do navbar
                navbar.insertBefore(headerLogo, navbar.firstChild);
            } else {
                headerLogo.style.display = 'flex';
            }
        } else {
            // Barra lateral visível: esconder logótipo do header
            if (headerLogo) {
                headerLogo.style.display = 'none';
            }
        }
    };

    updateHeaderLayout();

    // O MutationObserver na sidebar cobre recolher/expandir (muda style/class).
    // Sem setInterval — era um timer eterno a forçar reflow 2x/seg em todas as páginas.
    const observer = new MutationObserver(updateHeaderLayout);
    observer.observe(sidebarMain, {
        attributes: true,
        attributeFilter: ['style', 'class'],
        subtree: false,
    });
};

// Auto-scroll para próxima seção quando preenchida
const setupAutoScroll = () => {
    const form = document.querySelector('form');
    if (!form) return;

    const scrollToNextEmptySection = () => {
        // Encontrar todas as seções (divs com classe que indicam seção do Filament)
        const sections = Array.from(document.querySelectorAll('[role="region"], .space-y-6 > div'));

        for (let i = 0; i < sections.length; i++) {
            const section = sections[i];

            // Verificar se tem ring verde (seção completa)
            const hasGreenRing = section.className.includes('ring-green-500') ||
                                section.querySelector('[class*="ring-green"]') !== null;

            if (hasGreenRing && i < sections.length - 1) {
                // Encontrar a próxima seção vazia (sem ring verde)
                for (let j = i + 1; j < sections.length; j++) {
                    const nextSection = sections[j];
                    const nextHasRing = nextSection.className.includes('ring-green-500') ||
                                       nextSection.querySelector('[class*="ring-green"]') !== null;

                    if (!nextHasRing) {
                        // Fazer scroll suave para a próxima seção vazia
                        setTimeout(() => {
                            nextSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 200);
                        return;
                    }
                }
            }
        }
    };

    // Debounce: o handler varre o DOM inteiro (querySelectorAll); sem isto
    // corria a cada tecla num formulário enorme.
    let scrollTimer = null;
    const scrollDebounced = () => {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(scrollToNextEmptySection, 300);
    };

    // Listener para mudanças no formulário
    form.addEventListener('change', scrollDebounced);
    form.addEventListener('input', scrollDebounced);
};

// Toast notification when draft is restored
const showDraftRestoredToast = (formKey, component) => {
    if (document.getElementById('mmc-draft-toast')) return;

    const toast = document.createElement('div');
    toast.id = 'mmc-draft-toast';
    toast.className = 'fixed bottom-20 left-4 right-4 md:left-auto md:right-4 bg-gray-900/95 backdrop-blur text-white px-4 py-3 rounded-xl shadow-xl flex items-center justify-between gap-4 border border-white/10 z-50 transition-all duration-300 transform translate-y-10 opacity-0';
    toast.innerHTML = `
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            <p class="text-sm font-medium">Rascunho anterior restaurado automaticamente.</p>
        </div>
        <button id="clear-draft-btn" class="text-xs uppercase font-semibold tracking-wider text-rose-400 hover:text-rose-300 transition px-2 py-1 rounded bg-white/5 hover:bg-white/10">
            Limpar
        </button>
    `;
    document.body.appendChild(toast);

    // Slide in
    setTimeout(() => {
        toast.classList.remove('translate-y-10', 'opacity-0');
    }, 50);

    document.getElementById('clear-draft-btn').addEventListener('click', () => {
        localStorage.removeItem(formKey);
        // Clear all timers as well
        Object.keys(localStorage).forEach(key => {
            if (key.startsWith('mmc_timer_')) {
                localStorage.removeItem(key);
            }
        });
        toast.remove();
        // Reset Livewire form state and reload
        component.set('data', {});
        window.location.reload();
    });

    // Auto-fade out after 8 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.classList.add('translate-y-10', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }
    }, 8000);
};

// Auto-save form draft in localStorage for Daily Record creation
const setupFormDraft = () => {
    if (!window.location.pathname.includes('/daily-records/create')) return;

    const findAndRestore = () => {
        const mainComponentEl = document.querySelector('[wire\\:id]');
        if (!mainComponentEl) return;
        const componentId = mainComponentEl.getAttribute('wire:id');
        const component = window.Livewire ? window.Livewire.find(componentId) : null;
        
        if (!component) {
            // Try again in 100ms
            setTimeout(findAndRestore, 100);
            return;
        }

        const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');

        // 1. Restore draft
        const draft = localStorage.getItem(formKey);
        if (draft) {
            try {
                const draftData = JSON.parse(draft);
                if (draftData && Object.keys(draftData).length > 0) {
                    component.set('data', draftData);
                    showDraftRestoredToast(formKey, component);
                }
            } catch (e) {
                console.error('Error restoring daily record draft:', e);
            }
        }

        // 2. Setup local input change listener for quick updates
        const form = document.querySelector('form');
        if (form) {
            let debounceTimeout;
            form.addEventListener('input', () => {
                clearTimeout(debounceTimeout);
                debounceTimeout = setTimeout(() => {
                    const currentData = component.get('data');
                    if (currentData) {
                        localStorage.setItem(formKey, JSON.stringify(currentData));
                    }
                }, 500);
            });
        }
    };

    findAndRestore();
};

const setupGlobalImageLightbox = () => {
    document.addEventListener('click', (e) => {
        // 1. Check if clicked element or parent is an image/link inside an infolist image entry
        const infolistEl = e.target.closest('.fi-in-image img, .fi-in-image a, .fi-ta-image img');
        if (infolistEl) {
            e.preventDefault();
            e.stopPropagation();
            const src = infolistEl.tagName === 'IMG' ? infolistEl.src : infolistEl.href;
            if (src && typeof window.GLightbox !== 'undefined') {
                window.GLightbox({ elements: [{ href: src, type: 'image' }], touchNavigation: true, loop: false, zoomable: true, draggable: true }).open();
            }
            return;
        }

        // 2. Check if clicked element or parent is a FilePond image preview canvas
        const canvasContainer = e.target.closest('.filepond--image-preview-wrapper canvas, .filepond--image-preview');
        if (canvasContainer) {
            e.preventDefault();
            e.stopPropagation();
            try {
                const canvasEl = canvasContainer.tagName === 'CANVAS' ? canvasContainer : canvasContainer.querySelector('canvas');
                if (canvasEl) {
                    const dataUrl = canvasEl.toDataURL('image/jpeg', 0.95);
                    if (typeof window.GLightbox !== 'undefined') {
                        window.GLightbox({ elements: [{ href: dataUrl, type: 'image' }], touchNavigation: true, loop: false, zoomable: true, draggable: true }).open();
                    } else {
                        const win = window.open();
                        if (win) {
                            win.document.write(`<img src="${dataUrl}" style="max-width:100%; max-height:100vh; display:block; margin:auto;" />`);
                        }
                    }
                }
            } catch (err) {
                console.error('Error opening image preview:', err);
            }
            return;
        }

        // 3. Check if clicked element is an anchor link pointing to a storage image or image file
        const anchor = e.target.closest('a');
        if (anchor) {
            const href = anchor.getAttribute('href');
            if (href) {
                const isImage = anchor.classList.contains('glightbox-trigger') ||
                              href.match(/\.(jpeg|jpg|png|webp|gif|svg|heic|heif)(?:\?.*)?$/i) ||
                              href.includes('/storage/') ||
                              href.includes('r2.dev') ||
                              href.includes('/app/private/') ||
                              anchor.closest('.filepond--file') !== null;

                if (isImage) {
                    if (anchor.classList.contains('filepond--action-remove-item') || anchor.hasAttribute('download')) {
                        return;
                    }

                    e.preventDefault();
                    e.stopPropagation();
                    if (typeof window.GLightbox !== 'undefined') {
                        window.GLightbox({ elements: [{ href: href, type: 'image' }], touchNavigation: true, loop: false, zoomable: true, draggable: true }).open();
                    } else {
                        window.open(href, '_blank');
                    }
                }
            }
        }
    });
};

// Montagem única — flag evita observers/listeners duplicados se o DOMContentLoaded
// e o ramo readyState dispararem ambos, ou se o bundle reexecutar.
let mmcSetupDone = false;
const mmcSetup = () => {
    if (!document.documentElement.classList.contains('mmc-loaded')) {
        document.documentElement.classList.add('mmc-loaded');

        // Carrega GSAP dinamicamente para animação global de entrada se necessário
        const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (!reduzMovimento) {
            import('gsap').then(({ gsap }) => {
                const pageContent = document.querySelector('.fi-main');
                if (pageContent) {
                    gsap.from(pageContent, {
                        opacity: 0,
                        y: 10,
                        duration: 0.4,
                        ease: 'power2.out',
                        clearProps: 'all'
                    });
                }
            });
        }
    }

    if (mmcSetupDone) return;
    mmcSetupDone = true;
    setupDecimalInputs();
    setupHeaderLayout();
    setupAutoScroll();
    setupFormDraft();
    setupGlobalImageLightbox();
};

document.addEventListener('DOMContentLoaded', mmcSetup);
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    mmcSetup();
}

// Global Livewire 3 init hooks
document.addEventListener('livewire:init', () => {
    // Watch Livewire request lifecycle to auto-save drafts on server updates (e.g. toggles, selections)
    Livewire.hook('request', ({ component, respond }) => {
        if (component.name === 'app.filament.resources.daily-record-resource.pages.create-daily-record' || 
            window.location.pathname.includes('/daily-records/create')) {
            respond(() => {
                const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');
                const currentData = component.get('data');
                if (currentData) {
                    localStorage.setItem(formKey, JSON.stringify(currentData));
                }
            });
        }
    });

    // Clear draft and all active timers when dailyRecordSaved event is emitted
    Livewire.on('dailyRecordSaved', () => {
        const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');
        localStorage.removeItem(formKey);
        Object.keys(localStorage).forEach(key => {
            if (key.startsWith('mmc_timer_')) {
                localStorage.removeItem(key);
            }
        });
    });
});

// Setup form draft on Livewire SPA page transitions
document.addEventListener('livewire:navigated', () => {
    setupFormDraft();
});
