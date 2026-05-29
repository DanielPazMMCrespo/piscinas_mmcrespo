import './bootstrap';

import Chart from 'chart.js/auto';

/**
 * Componente Alpine para os gráficos do painel de parâmetros.
 *
 * Dois modos:
 *  - 'mono-metrica'  : um parâmetro, várias piscinas (cor por piscina) + banda CN 14/DA.
 *  - 'multi-metrica' : uma piscina, vários parâmetros (cor por parâmetro), dois eixos
 *                      (cloro/temp à esquerda, pH à direita) para correlacionar pH<->cloro.
 *
 * Registado no Alpine do Filament via o evento global `alpine:init`.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('mmcChart', (config) => ({
        chart: null,

        init() {
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
            return {
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
        },

        render(novoConfig = null) {
            if (novoConfig) config = novoConfig;
            if (this.chart) this.chart.destroy();

            const c = this.cores();
            const n = config.labels.length;
            const datasets = [];
            let scales = {};

            if (config.modo === 'multi-metrica') {
                // Eixo principal (cloro/temp/transp) + eixo pH à direita.
                const ep = config.eixos.principal;
                const eph = config.eixos.ph;

                // Banda do cloro livre no eixo principal.
                if (config.bandaPrincipal) {
                    datasets.push(...this.bandaDatasets(config.bandaPrincipal, 'principal', n, c.banda));
                }
                // Banda do pH no eixo pH.
                if (eph.banda) {
                    datasets.push(...this.bandaDatasets(eph.banda, 'ph', n, c.banda));
                }

                config.series.forEach((s) => {
                    datasets.push(this.linhaDataset(s, s.eixo === 'ph' ? 'ph' : 'principal'));
                });

                scales = {
                    x: { grid: { display: false }, ticks: { color: c.texto, maxRotation: 0, autoSkipPadding: 16 } },
                    principal: {
                        type: 'linear', position: 'left',
                        min: ep.min, max: ep.max,
                        grid: { color: c.grelha },
                        ticks: { color: c.texto },
                        title: { display: true, text: ep.titulo, color: c.texto },
                    },
                    ph: {
                        type: 'linear', position: 'right',
                        min: eph.min, max: eph.max,
                        grid: { drawOnChartArea: false },
                        ticks: { color: c.texto },
                        title: { display: true, text: eph.titulo, color: c.texto },
                    },
                };
            } else {
                // mono-metrica: um parâmetro, várias piscinas.
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
            }

            this.chart = new Chart(this.$refs.canvas, {
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
                                label: (ctx) => {
                                    const u = config.modo === 'multi-metrica' ? '' : (config.unidade ? ' ' + config.unidade : '');
                                    return `${ctx.dataset.label}: ${ctx.formattedValue}${u}`;
                                },
                            },
                        },
                    },
                    scales,
                },
            });
        },
    }));
});
