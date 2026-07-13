import * as echarts from 'echarts/core';
import { LineChart } from 'echarts/charts';
import {
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DataZoomInsideComponent,
    DataZoomSliderComponent,
    MarkAreaComponent,
    VisualMapPiecewiseComponent,
    AxisPointerComponent,
    ToolboxComponent
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
    LineChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    DataZoomInsideComponent,
    DataZoomSliderComponent,
    MarkAreaComponent,
    VisualMapPiecewiseComponent,
    AxisPointerComponent,
    ToolboxComponent,
    CanvasRenderer
]);

const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function registarMmcEcharts(Alpine) {
    Alpine.data('mmcEcharts', (initialPayload = null) => ({
        chart: null,
        resizeObserver: null,
        resizeTimer: null,
        _destroyed: false,
        _rafId: null,
        _offChartUpdate: null,
        _payload: initialPayload,
        _hasData: !!(initialPayload && Array.isArray(initialPayload.graphs) && initialPayload.graphs.length > 0),
        _renderRetries: 0,

        get _hasSeries() {
            const g = this._payload?.graphs;
            return Array.isArray(g) && g.some(graph => Array.isArray(graph.series) && graph.series.some(s => (s.data || []).length > 0));
        },

        init() {
            if (typeof Livewire !== 'undefined') {
                this._offChartUpdate = Livewire.on('mmc-chart-update', (eventData) => {
                    if (this._destroyed) return;
                    const payload = eventData?.payload ?? eventData;
                    if (!payload) return;

                    this._payload = payload;
                    this._hasData = Array.isArray(payload.graphs) && payload.graphs.length > 0;

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
                this.resizeTimer = setTimeout(() => {
                    if (this._destroyed) return;
                    if (this.chart) {
                        this.chart.resize();
                    } else if (this._hasData) {
                        this.render();
                    }
                }, 100);
            });
            this.resizeObserver.observe(this.$el);

            if (this._hasData) {
                this.$nextTick(() => { if (!this._destroyed) this.render(); });
            }
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

        pontos(serie) {
            return (serie.data || []).map((d) => {
                const real = d.y;
                return { value: [d.x, real, real] };
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
            if (!p || !Array.isArray(p.graphs)) return;

            if (!this.chart) {
                this.chart = echarts.init(el, null, { renderer: 'canvas' });
            }

            const c = this.cores();
            const seriesEcharts = [];
            const visualMaps = [];
            const yAxis = [];
            const xAxis = [];
            const grids = [];

            const totalGraphs = p.graphs.length;
            const topMargin = 50;
            const bottomMargin = 80;
            const availableHeight = el.offsetHeight - topMargin - bottomMargin;
            const graphHeight = totalGraphs > 0 ? (availableHeight / totalGraphs) - 20 : 0;

            p.graphs.forEach((graph, gridIndex) => {
                const isLast = gridIndex === totalGraphs - 1;
                const gridTop = topMargin + (gridIndex * (graphHeight + 20));

                grids.push({
                    top: gridTop,
                    height: graphHeight,
                    left: 56,
                    right: 56,
                });

                xAxis.push({
                    gridIndex: gridIndex,
                    type: 'time',
                    axisLine: { lineStyle: { color: c.grelha } },
                    axisLabel: {
                        show: isLast,
                        color: c.texto,
                        formatter: {
                            year: '{yyyy}', month: '{dd}/{MM}', day: '{dd}/{MM}',
                            hour: '{HH}:{mm}', minute: '{HH}:{mm}',
                        },
                    },
                    splitLine: { show: false },
                    axisPointer: { show: true, type: 'cross', label: { show: false } }
                });

                graph.series.forEach((serie, seriesIndexInGraph) => {
                    const isRightAxis = seriesIndexInGraph > 0;
                    
                    yAxis.push(this.yAxisReal(serie, isRightAxis ? 'right' : 'left', c, gridIndex));
                    const yAxisIndex = yAxis.length - 1;
                    const banda = serie.banda;

                    const s = {
                        name: serie.label,
                        type: 'line',
                        xAxisIndex: gridIndex,
                        yAxisIndex: yAxisIndex,
                        smooth: 0.3,
                        showSymbol: (serie.data || []).length <= 60,
                        symbolSize: 6,
                        lineStyle: { width: 2.5, color: serie.cor },
                        itemStyle: { color: serie.cor },
                        connectNulls: false,
                        data: this.pontos(serie),
                        encode: { x: 0, y: 1 },
                        _casas: serie.casas,
                        _unidade: serie.unidade
                    };

                    if (banda) {
                        s.markArea = {
                            silent: true,
                            itemStyle: { color: c.banda },
                            data: [[{ yAxis: banda.min }, { yAxis: banda.max }]],
                        };
                        visualMaps.push({
                            show: false,
                            type: 'piecewise',
                            seriesIndex: seriesEcharts.length,
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
            });

            const isShort = p.period === '6h' || p.period === '24h';

            const option = {
                animation: !reduzMovimento,
                grid: grids,
                toolbox: {
                    show: true,
                    feature: {
                        dataZoom: { yAxisIndex: 'none' },
                        restore: {},
                        saveAsImage: {}
                    },
                    iconStyle: { borderColor: c.texto },
                    top: 10,
                    right: 48
                },
                axisPointer: { link: { xAxisIndex: 'all' } },
                legend: {
                    show: true,
                    bottom: 40,
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
                            const real = it.value[2];
                            const sEchart = option.series[it.seriesIndex];
                            const casas = sEchart._casas ?? 2;
                            const u = sEchart._unidade ? ' ' + sEchart._unidade : '';
                            const v = Number(real).toLocaleString('pt-PT', {
                                minimumFractionDigits: casas, maximumFractionDigits: casas,
                            });
                            return `${it.marker}${it.seriesName}: <b>${v}${u}</b>`;
                        });
                        return `<div style="font-size:11px;opacity:.7;margin-bottom:4px">${head}</div>${linhas.join('<br>')}`;
                    },
                },
                dataZoom: [
                    { type: 'inside', xAxisIndex: xAxis.map((_, i) => i), throttle: 50 },
                    {
                        type: 'slider', xAxisIndex: xAxis.map((_, i) => i), height: 34, bottom: 4,
                        handleSize: 44, moveHandleSize: 8,
                        borderColor: c.grelha,
                    },
                ],
                xAxis,
                yAxis,
                series: seriesEcharts,
                visualMap: visualMaps,
            };

            this.chart.setOption(option, { notMerge: true });
            this.chart.resize();
        },

        yAxisReal(serie, lado, c, gridIndex) {
            return {
                gridIndex: gridIndex,
                type: 'value',
                position: lado,
                scale: true,
                min: (value) => isFinite(value.min) ? Math.min(value.min, serie.yMin) : serie.yMin,
                max: (value) => isFinite(value.max) ? Math.max(value.max, serie.yMax) : serie.yMax,
                name: serie.unidade ? `${serie.label} (${serie.unidade})` : serie.label,
                nameTextStyle: { color: serie.cor, fontSize: 11 },
                axisLabel: { color: serie.cor },
                splitLine: lado === 'left' ? { lineStyle: { color: c.grelha } } : { show: false },
            };
        },
    }));
}
