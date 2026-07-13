const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// ECharts core partilhado entre instâncias (import tree-shaken, uma só vez).
let EChartsCore = null;

async function carregarECharts() {
    if (EChartsCore) return EChartsCore;

    const [core, charts, comps, renderers] = await Promise.all([
        import('echarts/core'),
        import('echarts/charts'),
        import('echarts/components'),
        import('echarts/renderers'),
    ]);

    core.use([
        charts.LineChart,
        comps.GridComponent,
        comps.TooltipComponent,
        comps.LegendComponent,
        comps.DataZoomComponent,
        comps.MarkAreaComponent,
        comps.VisualMapComponent,
        renderers.CanvasRenderer,
    ]);

    EChartsCore = core;
    return core;
}

export function registarMmcEcharts(Alpine) {
    Alpine.data('mmcEcharts', (initialPayload = null) => ({
        chart: null,
        resizeObserver: null,
        resizeTimer: null,
        _destroyed: false,
        _rafId: null,
        _offChartUpdate: null,
        _payload: initialPayload,
        _hasData: !!(initialPayload && Array.isArray(initialPayload.series)),
        _renderRetries: 0,

        get _hasSeries() {
            const s = this._payload?.series;
            return Array.isArray(s) && s.some((serie) => (serie.data || []).length > 0);
        },

        async init() {
            await carregarECharts();

            if (this._hasData) {
                this.$nextTick(() => { if (!this._destroyed) this.render(); });
            }

            if (typeof Livewire !== 'undefined') {
                this._offChartUpdate = Livewire.on('mmc-chart-update', (eventData) => {
                    if (this._destroyed) return;
                    const payload = eventData?.payload ?? eventData;
                    if (!payload) return;

                    this._payload = payload;
                    this._hasData = Array.isArray(payload.series);

                    if (this._hasData) {
                        this.$nextTick(() => { if (!this._destroyed) this.render(); });
                    } else if (this.chart) {
                        this.chart.dispose();
                        this.chart = null;
                    }
                });
            }

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
            if (this._rafId) { cancelAnimationFrame(this._rafId); this._rafId = null; }
            if (this._offChartUpdate) { this._offChartUpdate(); this._offChartUpdate = null; }
            this.resizeObserver?.disconnect();
            if (this.chart) { this.chart.dispose(); this.chart = null; }
        },

        resetZoom() {
            this.chart?.dispatchAction({ type: 'dataZoom', start: 0, end: 100 });
        },

        escuro() {
            return document.documentElement.classList.contains('dark');
        },

        cores() {
            const e = this.escuro();
            return {
                texto:  e ? 'rgba(255,255,255,0.65)' : 'rgba(0,0,0,0.6)',
                grelha: e ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)',
                banda:  e ? 'rgba(118,184,42,0.12)'  : 'rgba(118,184,42,0.14)',
                fundoTooltip: e ? '#1d2a1e' : '#ffffff',
                textoTooltip: e ? '#ffffff' : '#1d2a1e',
            };
        },

        // Cada ponto é [xIso, valorTracado, valorReal]. Em dual/multi-piscina
        // tracado === real; em multi-metrica tracado é normalizado 0-100.
        pontos(serie, normalizar) {
            return (serie.data || []).map((d) => {
                const real = d.y;
                let tracado = real;
                if (normalizar) {
                    const span = (serie.yMax - serie.yMin) || 1;
                    tracado = ((real - serie.yMin) / span) * 100;
                }
                return { value: [d.x, tracado, real] };
            });
        },

        render() {
            if (this._destroyed) return;
            const el = this.$refs.container;
            if (!el) return;

            if (el.offsetWidth === 0 || el.offsetHeight === 0) {
                if (this._renderRetries < 15) {
                    this._renderRetries++;
                    this._rafId = requestAnimationFrame(() => { if (!this._destroyed) this.render(); });
                }
                return;
            }
            this._renderRetries = 0;

            const p = this._payload;
            if (!p || !Array.isArray(p.series)) return;

            if (!this.chart) {
                this.chart = EChartsCore.init(el, null, { renderer: 'canvas' });
            }

            const c = this.cores();
            const modo = p.mode;
            const normalizar = modo === 'multi-metrica';

            const seriesEcharts = [];
            const visualMaps = [];
            const yAxis = [];

            if (modo === 'dual') {
                yAxis.push(this.yAxisReal(p.series[0], 'left', c));
                yAxis.push(this.yAxisReal(p.series[1], 'right', c));
            } else if (normalizar) {
                yAxis.push({
                    type: 'value', min: 0, max: 100, scale: false,
                    name: '% do intervalo', nameTextStyle: { color: c.texto, fontSize: 11 },
                    axisLabel: { color: c.texto, formatter: '{value}%' },
                    splitLine: { lineStyle: { color: c.grelha } },
                });
            } else {
                yAxis.push({
                    type: 'value', scale: true,
                    name: p.metrica?.unidade || '',
                    nameTextStyle: { color: c.texto, fontSize: 11 },
                    axisLabel: { color: c.texto },
                    splitLine: { lineStyle: { color: c.grelha } },
                });
            }

            p.series.forEach((serie, i) => {
                const yAxisIndex = modo === 'dual' ? (serie.axis === 'right' ? 1 : 0) : 0;
                const banda = modo === 'multi-piscina' ? p.metrica?.banda : serie.banda;

                const s = {
                    name: serie.label,
                    type: 'line',
                    yAxisIndex,
                    smooth: 0.3,
                    showSymbol: (serie.data || []).length <= 60,
                    symbolSize: 6,
                    lineStyle: { width: 2.5, color: serie.cor },
                    itemStyle: { color: serie.cor },
                    connectNulls: false,
                    data: this.pontos(serie, normalizar),
                    encode: { x: 0, y: 1 },
                };

                // markArea da banda legal — só nos modos com eixo real.
                if (banda && modo !== 'multi-metrica') {
                    s.markArea = {
                        silent: true,
                        itemStyle: { color: c.banda },
                        data: [[{ yAxis: banda.min }, { yAxis: banda.max }]],
                    };
                }

                // visualMap pinta a vermelho o troço fora da banda (dim 2 = valor real).
                if (banda) {
                    visualMaps.push({
                        show: false,
                        type: 'piecewise',
                        seriesIndex: i,
                        dimension: 2,
                        pieces: [
                            { lt: banda.min, color: '#dc2626' },
                            { gte: banda.min, lte: banda.max, color: serie.cor },
                            { gt: banda.max, color: '#dc2626' },
                        ],
                        outOfRange: { color: serie.cor },
                    });
                }

                seriesEcharts.push(s);
            });

            const isShort = p.period === '6h' || p.period === '24h';

            const option = {
                animation: !reduzMovimento,
                grid: { left: 48, right: modo === 'dual' ? 56 : 20, top: 24, bottom: 74 },
                legend: {
                    show: modo !== 'dual' || p.series.length > 1,
                    bottom: 44,
                    textStyle: { color: c.texto },
                    icon: 'roundRect',
                },
                tooltip: {
                    trigger: 'axis',
                    axisPointer: { type: 'cross', label: { show: false } },
                    confine: true,
                    backgroundColor: c.fundoTooltip,
                    borderColor: c.grelha,
                    textStyle: { color: c.textoTooltip },
                    formatter: (params) => {
                        if (!params.length) return '';
                        const dt = new Date(params[0].value[0]);
                        const dd = String(dt.getDate()).padStart(2, '0');
                        const mm = String(dt.getMonth() + 1).padStart(2, '0');
                        const hh = String(dt.getHours()).padStart(2, '0');
                        const mi = String(dt.getMinutes()).padStart(2, '0');
                        const head = isShort ? `${dd}/${mm} ${hh}:${mi}` : `${dd}/${mm}/${dt.getFullYear()}`;
                        const linhas = params.map((it) => {
                            const serie = p.series[it.seriesIndex];
                            const real = it.value[2];
                            const casas = serie?.casas ?? 2;
                            const u = modo === 'multi-piscina'
                                ? (p.metrica?.unidade ? ' ' + p.metrica.unidade : '')
                                : (serie?.unidade ? ' ' + serie.unidade : '');
                            const v = Number(real).toLocaleString('pt-PT', {
                                minimumFractionDigits: casas, maximumFractionDigits: casas,
                            });
                            return `${it.marker}${it.seriesName}: <b>${v}${u}</b>`;
                        });
                        return `<div style="font-size:11px;opacity:.7;margin-bottom:4px">${head}</div>${linhas.join('<br>')}`;
                    },
                },
                dataZoom: [
                    { type: 'inside', throttle: 50 },
                    {
                        type: 'slider', height: 34, bottom: 4,
                        handleSize: 44, moveHandleSize: 8,
                        borderColor: c.grelha,
                    },
                ],
                xAxis: {
                    type: 'time',
                    axisLine: { lineStyle: { color: c.grelha } },
                    axisLabel: {
                        color: c.texto,
                        formatter: {
                            year: '{yyyy}', month: '{dd}/{MM}', day: '{dd}/{MM}',
                            hour: '{HH}:{mm}', minute: '{HH}:{mm}',
                        },
                    },
                    splitLine: { show: false },
                },
                yAxis,
                series: seriesEcharts,
                visualMap: visualMaps,
            };

            this.chart.setOption(option, { notMerge: true });
            this.chart.resize();
        },

        yAxisReal(serie, lado, c) {
            return {
                type: 'value',
                position: lado,
                scale: true,
                name: serie.unidade ? `${serie.label} (${serie.unidade})` : serie.label,
                nameTextStyle: { color: serie.cor, fontSize: 11 },
                axisLabel: { color: serie.cor },
                splitLine: lado === 'left' ? { lineStyle: { color: c.grelha } } : { show: false },
            };
        },
    }));
}
