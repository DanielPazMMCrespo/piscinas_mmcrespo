import './bootstrap';


const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Shared Chart.js class (with plugins) across all mmcChart instances on the page.
// Avoids duplicate plugin registration and duplicate dynamic imports.
let ChartWithPlugins = null;

// _hasData (payload.left/right existem) só diz que há uma piscina selecionada
// e uma estrutura de eixos válida — não que haja pontos para desenhar. Sem
// isto, um período sem registos mostra um gráfico vazio (só a banda legal)
// em vez de uma mensagem clara.
function payloadHasSeries(payload) {
    if (!payload?.left || !payload?.right) return false;
    const hasPoints = (axis) => (axis?.datasets ?? []).some((d) => (d?.data ?? []).length > 0);
    return hasPoints(payload.left) || hasPoints(payload.right);
}

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
    window.Alpine.data('mmcChart', (initialPayload = null) => ({
        chart: null,
        resizeObserver: null,
        resizeTimer: null,
        _destroyed: false,
        _rafId: null,
        _offChartUpdate: null,
        _payload: initialPayload,
        _hasData: !!(initialPayload?.left && initialPayload?.right),
        _hasSeries: payloadHasSeries(initialPayload),
        _renderRetries: 0,

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
                    this._hasSeries = payloadHasSeries(payload);

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
                fill: false, // Desativado (Só Linha)
                borderWidth: 2.5, // Normal (2.5px)
                pointRadius: 4, // Sempre visíveis
                pointHoverRadius: 6,
                tension: 0.4, // Suave (Curvo)
                spanGaps: false,
                order: 1,
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
                                tooltipFormat: isShort ? 'dd/MM HH:mm' : 'dd/MM/yyyy',
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
     * Timer de retrolavagem: modal ecrã cheio ao iniciar, colapsa numa pill fixa
     * no topo ao tocar fora. Persistido em localStorage (timestamp absoluto de
     * fim, scoped por piscina) para sobreviver a reload/bloqueio de ecrã.
     */
    window.Alpine.data('mmcTimerRetrolavagem', (configTimers) => ({
        timers: {},
        intervaloId: null,
        ouvinteEvento: null,

        init() {
            configTimers.forEach((cfg) => {
                this.timers[cfg.campo] = {
                    label: cfg.label,
                    duracaoSegundos: cfg.duracaoSegundos,
                    fimEm: null,
                    segundosRestantesPausado: null,
                    pausado: false,
                    terminado: false,
                    modalAberto: false,
                    pillVisivel: false,
                    mostrarEditor: false,
                };
                this.restaurar(cfg.campo);
            });

            this.intervaloId = setInterval(() => this.atualizarTodos(), 250);

            this.ouvinteEvento = (evento) => {
                const campo = evento.detail?.campo;
                if (campo && this.timers[campo]) {
                    this.iniciar(campo);
                }
            };
            window.addEventListener('mmc-timer-iniciar', this.ouvinteEvento);
        },

        destroy() {
            clearInterval(this.intervaloId);
            if (this.ouvinteEvento) {
                window.removeEventListener('mmc-timer-iniciar', this.ouvinteEvento);
            }
        },

        poolIdAtual() {
            return this.$wire?.data?.pool_id ?? 'sem_piscina';
        },

        chaveArmazenamento(campo) {
            return `mmc_timer_${this.poolIdAtual()}_${campo}`;
        },

        restaurar(campo) {
            const guardado = localStorage.getItem(this.chaveArmazenamento(campo));
            if (!guardado) return;

            let dados;
            try {
                dados = JSON.parse(guardado);
            } catch (erro) {
                localStorage.removeItem(this.chaveArmazenamento(campo));
                return;
            }

            if (dados.fimEm && dados.fimEm > Date.now()) {
                this.timers[campo].duracaoSegundos = dados.duracaoSegundos;
                this.timers[campo].fimEm = dados.fimEm;
                this.timers[campo].pillVisivel = true;
            } else {
                localStorage.removeItem(this.chaveArmazenamento(campo));
            }
        },

        guardar(campo) {
            const t = this.timers[campo];
            localStorage.setItem(this.chaveArmazenamento(campo), JSON.stringify({
                duracaoSegundos: t.duracaoSegundos,
                fimEm: t.fimEm,
            }));
        },

        limpar(campo) {
            localStorage.removeItem(this.chaveArmazenamento(campo));
        },

        iniciar(campo) {
            const t = this.timers[campo];
            t.terminado = false;
            t.pausado = false;
            t.fimEm = Date.now() + t.duracaoSegundos * 1000;
            t.modalAberto = true;
            t.pillVisivel = false;
            this.guardar(campo);
        },

        atualizarTodos() {
            Object.keys(this.timers).forEach((campo) => this.atualizar(campo));
        },

        atualizar(campo) {
            const t = this.timers[campo];
            if (t.pausado || t.fimEm === null || t.terminado) return;

            if (this.segundosRestantes(campo) <= 0) {
                t.terminado = true;
                t.pillVisivel = true;
                this.guardar(campo);
                this.notificarFim();
            }
        },

        segundosRestantes(campo) {
            const t = this.timers[campo];
            if (t.fimEm === null) return 0;
            return Math.max(0, Math.round((t.fimEm - Date.now()) / 1000));
        },

        progresso(campo) {
            const t = this.timers[campo];
            if (t.duracaoSegundos <= 0) return 0;
            return Math.min(1, 1 - this.segundosRestantes(campo) / t.duracaoSegundos);
        },

        formatoTempo(campo) {
            const total = this.segundosRestantes(campo);
            const minutos = Math.floor(total / 60).toString().padStart(2, '0');
            const segundos = (total % 60).toString().padStart(2, '0');
            return `${minutos}:${segundos}`;
        },

        algumaPillVisivel() {
            return Object.values(this.timers).some((t) => t.pillVisivel);
        },

        colapsar(campo) {
            this.timers[campo].modalAberto = false;
            this.timers[campo].pillVisivel = true;
        },

        expandir(campo) {
            this.timers[campo].modalAberto = true;
            this.timers[campo].pillVisivel = false;
        },

        cancelar(campo) {
            const t = this.timers[campo];
            t.modalAberto = false;
            t.pillVisivel = false;
            t.terminado = false;
            t.pausado = false;
            t.fimEm = null;
            this.limpar(campo);
        },

        alternarPausa(campo) {
            const t = this.timers[campo];
            if (t.pausado) {
                t.fimEm = Date.now() + t.segundosRestantesPausado;
                t.pausado = false;
            } else {
                t.segundosRestantesPausado = t.fimEm - Date.now();
                t.pausado = true;
            }
            this.guardar(campo);
        },

        ajustarMinutos(campo, delta) {
            const t = this.timers[campo];
            const novaDuracao = Math.max(60, t.duracaoSegundos + delta * 60);
            const restanteAtual = this.segundosRestantes(campo);
            t.duracaoSegundos = novaDuracao;
            if (t.fimEm !== null) {
                t.fimEm = Date.now() + Math.min(restanteAtual + delta * 60, novaDuracao) * 1000;
            }
            this.guardar(campo);
        },

        notificarFim() {
            if (navigator.vibrate) {
                navigator.vibrate([200, 100, 200]);
            }
            try {
                const contexto = new (window.AudioContext || window.webkitAudioContext)();
                const oscilador = contexto.createOscillator();
                oscilador.frequency.value = 880;
                oscilador.connect(contexto.destination);
                oscilador.start();
                oscilador.stop(contexto.currentTime + 0.3);
            } catch (erro) {
                // Ambiente sem suporte a Web Audio — pill já fica visível/vermelha.
            }
        },
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

// Auto-save form draft in localStorage for Daily Record creation
const setupFormDraft = () => {
    if (!window.location.pathname.includes('/daily-records/create')) return;

    const form = document.querySelector('form');
    if (!form) return;

    const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');

    // Restore draft after a small timeout to let Livewire/Filament bindings initialize
    setTimeout(() => {
        const draft = localStorage.getItem(formKey);
        if (draft) {
            try {
                const data = JSON.parse(draft);
                Object.entries(data).forEach(([name, val]) => {
                    const input = form.querySelector(`[name="${name}"], [name*="${name}"]`);
                    if (input) {
                        if (input.type === 'checkbox' || input.type === 'radio') {
                            input.checked = !!val;
                        } else {
                            input.value = val;
                        }
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            } catch (e) {
                console.error('Error restoring draft:', e);
            }
        }
    }, 500);

    // Save draft on input
    form.addEventListener('input', (e) => {
        const el = e.target;
        if (!el.name) return;

        const currentDraft = localStorage.getItem(formKey);
        let data = {};
        try {
            data = currentDraft ? JSON.parse(currentDraft) : {};
        } catch (e) {
            data = {};
        }

        if (el.type === 'checkbox' || el.type === 'radio') {
            data[el.name] = el.checked;
        } else {
            data[el.name] = el.value;
        }

        localStorage.setItem(formKey, JSON.stringify(data));
    });

    // Clear draft on form submit
    form.addEventListener('submit', () => {
        localStorage.removeItem(formKey);
    });

    // Also clear draft when Filament notifies that the record was successfully saved
    if (window.Livewire) {
        window.Livewire.on('dailyRecordSaved', (event) => {
            if (event.notification && event.notification.status === 'success') {
                localStorage.removeItem(formKey);
            }
        });
    }
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

// A sidebar do Filament persiste `isOpen: true` por defeito (Alpine.$persist),
// independente do viewport — em mobile isto mostra o menu em overlay por cima
// do dashboard no primeiro acesso. 'alpine:initialized' corre depois do Alpine
// arrancar por completo (após todos os stores serem registados), por isso não
// há corrida com o store 'sidebar' do próprio Filament. Só força o fecho no
// full-page-load: navegação Livewire (wire:navigate) não reinicializa o Alpine,
// por isso um utilizador que abra o menu manualmente mantém-no aberto ao navegar.
document.addEventListener('alpine:initialized', () => {
    if (window.innerWidth < 1024 && window.Alpine?.store('sidebar')) {
        window.Alpine.store('sidebar').close();
    }
});
