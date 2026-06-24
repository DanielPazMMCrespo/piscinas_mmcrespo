import './bootstrap';


const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Componente Alpine para os gráficos do painel de parâmetros.
 *
 * Dois modos:
 *  - 'mono-metrica'  : um parâmetro, várias piscinas (cor por piscina) + banda CN 14/DA.
 *                      Eixo Y com valores reais (mg/L, °C, etc.).
 *  - 'multi-metrica' : uma piscina, vários parâmetros (cor por parâmetro).
 *                      Eixo Y único normalizado 0-100% do intervalo legal (CN 14/DA).
 *                      A banda verde cobre exatamente 0-100. suggestedMin:-20 /
 *                      suggestedMax:120 para linhas fora de gama ficarem visíveis.
 *                      O tooltip mostra os valores REAIS (ex: "pH 7,42", "Cloro Livre 1,20 mg/L")
 *                      lidos de dataset.dataReal[dataIndex].
 *
 * Registado no Alpine do Filament via o evento global `alpine:init`.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('mmcChart', (config) => ({
        chart: null,
        resizeObserver: null,
        resizeTimer: null,
        ChartClass: null,

        async init() {
            // Carregamento dinâmico do Chart.js apenas quando necessário (code splitting)
            if (!this.ChartClass) {
                const chartJs = await import('chart.js');
                this.ChartClass = chartJs.Chart;
                this.ChartClass.register(
                    chartJs.LineController,
                    chartJs.LineElement,
                    chartJs.PointElement,
                    chartJs.LinearScale,
                    chartJs.CategoryScale,
                    chartJs.Filler,
                    chartJs.Legend,
                    chartJs.Tooltip
                );
            }

            this.render();

            Livewire.on('mmc-chart-updated', () => {
                this.$nextTick(() => {
                    if (this.$refs.payload) {
                        this.render(JSON.parse(this.$refs.payload.textContent));
                    }
                });
            });

            Alpine.effect(() => {
                Alpine.store('theme');
                this.$nextTick(() => this.render());
            });

            // Rotação/redimensionamento em mobile: o canvas não acompanha o
            // contentor sem um resize explícito. Debounce de 100ms.
            this.resizeObserver = new ResizeObserver(() => {
                clearTimeout(this.resizeTimer);
                this.resizeTimer = setTimeout(() => this.chart?.resize(), 100);
            });
            this.resizeObserver.observe(this.$el);
        },

        destroy() {
            this.resizeObserver?.disconnect();
            this.chart?.destroy();
        },

        cores() {
            const escuro = document.documentElement.classList.contains('dark');
            return {
                texto: escuro ? 'rgba(255,255,255,0.65)' : 'rgba(0,0,0,0.6)',
                grelha: escuro ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)',
                banda: escuro ? 'rgba(118,184,42,0.12)' : 'rgba(118,184,42,0.14)',
            };
        },

        // Datasets da banda de conformidade (faixa verde) para um dado eixo.
        bandaDatasets(banda, eixoId, nLabels, corBanda) {
            return [
                {
                    label: '__banda_max_' + eixoId,
                    data: Array(nLabels).fill(banda.max),
                    yAxisID: eixoId,
                    borderWidth: 0, pointRadius: 0,
                    fill: '+1', backgroundColor: corBanda, order: 99,
                },
                {
                    label: '__banda_min_' + eixoId,
                    data: Array(nLabels).fill(banda.min),
                    yAxisID: eixoId,
                    borderWidth: 0, pointRadius: 0, fill: false, order: 99,
                },
            ];
        },

        linhaDataset(s, eixoId) {
            const ds = {
                label: s.label,
                data: s.data,
                yAxisID: eixoId,
                borderColor: s.cor,
                backgroundColor: s.cor,
                borderWidth: 2.5,
                pointRadius: 0,
                pointHoverRadius: 4,
                tension: 0.35,
                spanGaps: false,
                order: 1,
            };
            // No modo multi-metrica os arrays dataReal e unidade são transportados
            // no dataset para o callback do tooltip os poder ler diretamente.
            if (s.dataReal !== undefined) ds.dataReal = s.dataReal;
            if (s.unidade !== undefined) ds.unidade = s.unidade;
            // Métricas do controlador renderizam a tracejado para distinguir do registo manual.
            if (s.dashed) ds.borderDash = [5, 5];
            return ds;
        },

        render(novoConfig = null) {
            if (novoConfig) config = novoConfig;
            if (this.chart) this.chart.destroy();

            const c = this.cores();
            const n = config.labels.length;
            const datasets = [];
            let scales = {};
            // Callback de tooltip — definido por modo abaixo.
            let tooltipLabel = null;

            if (config.modo === 'multi-metrica') {
                // Eixo Y único normalizado 0-100% do intervalo legal CN 14/DA.
                // bandaNormalizada = {min:0, max:100} (a banda "conforme" cobre todo o eixo).
                // suggestedMin:-20 / suggestedMax:120 para ver valores fora de gama.
                if (config.bandaNormalizada) {
                    datasets.push(...this.bandaDatasets(config.bandaNormalizada, 'y', n, c.banda));
                }

                config.series.forEach((s) => {
                    datasets.push(this.linhaDataset(s, 'y'));
                });

                scales = {
                    x: { grid: { display: false }, ticks: { color: c.texto, maxRotation: 0, autoSkipPadding: 16 } },
                    y: {
                        suggestedMin: -20,
                        suggestedMax: 120,
                        grid: { color: c.grelha },
                        ticks: {
                            color: c.texto,
                            callback: (v) => v + '%',
                        },
                        title: { display: true, text: '% do intervalo legal', color: c.texto },
                    },
                };

                // Tooltip mostra o valor REAL da série (dataset.dataReal[dataIndex])
                // em vez do valor normalizado. Ex: "pH: 7,42" ou "Cloro Livre: 1,20 mg/L".
                tooltipLabel = (ctx) => {
                    if (ctx.dataset.label.startsWith('__banda')) return null;
                    const real = ctx.dataset.dataReal ? ctx.dataset.dataReal[ctx.dataIndex] : null;
                    if (real === null || real === undefined) return `${ctx.dataset.label}: —`;
                    const unidade = ctx.dataset.unidade || '';
                    // dataReal já inclui a unidade formatada (feito no PHP).
                    return `${ctx.dataset.label}: ${real}`;
                };
            } else {
                // mono-metrica: um parâmetro, várias piscinas — sem normalização.
                if (config.banda) {
                    datasets.push(...this.bandaDatasets(config.banda, 'y', n, c.banda));
                }
                config.series.forEach((s) => datasets.push(this.linhaDataset(s, 'y')));

                scales = {
                    x: { grid: { display: false }, ticks: { color: c.texto, maxRotation: 0, autoSkipPadding: 16 } },
                    y: {
                        min: config.min, max: config.max,
                        grid: { color: c.grelha },
                        ticks: { color: c.texto },
                        title: config.unidade ? { display: true, text: config.unidade, color: c.texto } : { display: false },
                    },
                };

                tooltipLabel = (ctx) => {
                    if (ctx.dataset.label.startsWith('__banda')) return null;
                    const u = config.unidade ? ' ' + config.unidade : '';
                    return `${ctx.dataset.label}: ${ctx.formattedValue}${u}`;
                };
            }

            this.chart = new this.ChartClass(this.$refs.canvas, {
                type: 'line',
                data: { labels: config.labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            display: true, position: 'bottom',
                            labels: {
                                color: c.texto, boxWidth: 10, boxHeight: 10,
                                usePointStyle: true, pointStyle: 'line',
                                filter: (item) => !item.text.startsWith('__banda'),
                            },
                        },
                        tooltip: {
                            filter: (item) => !item.dataset.label.startsWith('__banda'),
                            callbacks: {
                                label: tooltipLabel,
                            },
                        },
                    },
                    scales,
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
            this.sortables.forEach((s) => s.destroy());
            this.sortables = [];

            this.$el.querySelectorAll('.mmc-kb-list').forEach((lista) => {
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
});

// Conversão e sanitização de vírgula para ponto em campos decimais.
// Delegado no document (capture) para cobrir inputs do Livewire e modais do Filament.
const setupDecimalInputs = () => {
    // 1) Tecla vírgula -> converte para ponto em tempo real (apenas se for type="text")
    document.addEventListener('keydown', (e) => {
        if (e.key !== ',') return;
        const el = e.target;
        if (el.tagName !== 'INPUT') return;
        
        const isDecimalInput = el.getAttribute('inputmode') === 'decimal' || el.type === 'number';
        if (!isDecimalInput) return;

        if (el.type === 'text') {
            e.preventDefault();
            const start = el.selectionStart ?? 0;
            const end = el.selectionEnd ?? 0;
            const val = el.value;
            
            // Se já existir um ponto e tentarem inserir outro na mesma posição, ignoramos
            if (val.includes('.') && start === end) {
                return;
            }
            
            el.value = val.slice(0, start) + '.' + val.slice(end);
            el.setSelectionRange(start + 1, start + 1);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }, { capture: true, passive: false });

    // 2) Evento input -> sanitiza o texto digitado (apenas números e no máximo um ponto)
    document.addEventListener('input', (e) => {
        const el = e.target;
        if (el.tagName !== 'INPUT') return;
        
        const isDecimalInput = el.getAttribute('inputmode') === 'decimal';
        if (!isDecimalInput || el.type !== 'text') return;

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

    // 3) Colar texto -> converte vírgulas para pontos e sanitiza
    document.addEventListener('paste', (e) => {
        const el = e.target;
        if (el.tagName !== 'INPUT') return;
        if (el.getAttribute('inputmode') !== 'decimal' || el.type !== 'text') return;

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
        scrollTimer = setTimeout(scrollToNextEmptySection, 150);
    };

    // Listener para mudanças no formulário
    form.addEventListener('change', scrollDebounced);
    form.addEventListener('input', scrollDebounced);

    // Monitorar mudanças nas classes (quando Filament adiciona ring-green-500)
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                if (mutation.target.className.includes('ring-green-500')) {
                    scrollToNextEmptySection();
                }
            }
        });
    });

    observer.observe(form, {
        attributes: true,
        attributeFilter: ['class'],
        subtree: true,
    });
};

// Auto-save form draft in localStorage for Daily Record creation
const setupFormDraft = () => {
    if (!window.location.pathname.includes('/daily-records/create')) return;

    const form = document.querySelector('form');
    if (!form) return;

    const formKey = 'daily_record_form_draft';

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
        window.Livewire.on('notificationSent', (event) => {
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
