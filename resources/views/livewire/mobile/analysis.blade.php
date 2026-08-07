<div class="flex-1 flex flex-col h-full px-4 pt-6">
    <header class="flex items-center justify-between mb-6 shrink-0">
        <h1 class="text-2xl font-bold tracking-tight text-white">Análise</h1>
        <div class="px-3 py-1 rounded-full bg-slate-800 text-xs font-semibold text-slate-300">
            Últimos 7 dias
        </div>
    </header>

    <div class="flex-1 flex flex-col relative w-full mb-4">
        <!-- Card for Chart -->
        <div class="w-full bg-slate-900 border border-slate-800 rounded-3xl p-4 shadow-xl shrink-0" 
             x-data="poolChart({
                 labels: {{ json_encode($labels) }},
                 ph: {{ json_encode($phData) }},
                 chlorine: {{ json_encode($chlorineData) }}
             })">
            
            <div class="flex items-center justify-between mb-4 px-2">
                <div class="flex items-center space-x-2">
                    <div class="w-3 h-3 rounded-full bg-fuchsia-500 shadow-[0_0_8px_rgba(217,70,239,0.8)]"></div>
                    <span class="text-xs font-medium text-slate-300">pH</span>
                </div>
                <div class="flex items-center space-x-2">
                    <div class="w-3 h-3 rounded-full bg-cyan-400 shadow-[0_0_8px_rgba(34,211,238,0.8)]"></div>
                    <span class="text-xs font-medium text-slate-300">Cloro Livre</span>
                </div>
            </div>

            <div class="relative w-full h-64">
                <canvas x-ref="canvas"></canvas>
            </div>
        </div>

        <!-- Opcional: Estatísticas extra -->
        <div class="grid grid-cols-2 gap-4 mt-4 shrink-0">
             <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 flex flex-col">
                 <span class="text-xs text-slate-400 font-medium mb-1">Média pH</span>
                 <span class="text-3xl font-bold text-fuchsia-400">7.27</span>
             </div>
             <div class="bg-slate-900 border border-slate-800 rounded-3xl p-4 flex flex-col">
                 <span class="text-xs text-slate-400 font-medium mb-1">Média Cloro</span>
                 <span class="text-3xl font-bold text-cyan-400">1.57</span>
             </div>
        </div>
    </div>
</div>

@script
<script>
    Alpine.data('poolChart', (data) => ({
        chart: null,
        init() {
            // Import dynamically from Vite's node_modules resolution
            import('chart.js/auto').then(({ default: Chart }) => {
                if(this.chart) {
                    this.chart.destroy();
                }
                const ctx = this.$refs.canvas.getContext('2d');
                
                // Gradients for modern look
                let phGradient = ctx.createLinearGradient(0, 0, 0, 300);
                phGradient.addColorStop(0, 'rgba(217, 70, 239, 0.4)'); // Fuchsia
                phGradient.addColorStop(1, 'rgba(217, 70, 239, 0.0)');
                
                let clGradient = ctx.createLinearGradient(0, 0, 0, 300);
                clGradient.addColorStop(0, 'rgba(34, 211, 238, 0.4)'); // Cyan
                clGradient.addColorStop(1, 'rgba(34, 211, 238, 0.0)');

                this.chart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: data.labels,
                        datasets: [
                            {
                                label: 'pH',
                                data: data.ph,
                                borderColor: '#d946ef',
                                backgroundColor: phGradient,
                                borderWidth: 3,
                                pointBackgroundColor: '#0f172a',
                                pointBorderColor: '#d946ef',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                fill: true,
                                tension: 0.4, // bezier curve
                                yAxisID: 'y'
                            },
                            {
                                label: 'Cloro',
                                data: data.chlorine,
                                borderColor: '#22d3ee',
                                backgroundColor: clGradient,
                                borderWidth: 3,
                                pointBackgroundColor: '#0f172a',
                                pointBorderColor: '#22d3ee',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 6,
                                fill: true,
                                tension: 0.4,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            mode: 'index',
                            intersect: false,
                        },
                        plugins: {
                            legend: {
                                display: false // Hidden in favor of custom legend
                            },
                            tooltip: {
                                backgroundColor: 'rgba(15, 23, 42, 0.95)',
                                titleColor: '#f8fafc',
                                bodyColor: '#cbd5e1',
                                borderColor: '#334155',
                                borderWidth: 1,
                                padding: 12,
                                boxPadding: 6,
                                cornerRadius: 8
                            }
                        },
                        scales: {
                            x: {
                                grid: {
                                    display: false, // No grid lines
                                    drawBorder: false
                                },
                                ticks: {
                                    color: '#64748b',
                                    font: {
                                        size: 12,
                                        weight: '600'
                                    }
                                },
                                border: { display: false }
                            },
                            y: {
                                type: 'linear',
                                display: true,
                                position: 'left',
                                min: 6.8,
                                max: 7.8,
                                grid: {
                                    display: false,
                                    drawTicks: false
                                },
                                ticks: {
                                    color: '#475569',
                                    maxTicksLimit: 5,
                                    font: { size: 10 },
                                    padding: 6
                                },
                                border: { display: false }
                            },
                            y1: {
                                type: 'linear',
                                display: true,
                                position: 'right',
                                min: 0.5,
                                max: 3.0,
                                grid: {
                                    display: false,
                                    drawTicks: false
                                },
                                ticks: {
                                    color: '#475569',
                                    maxTicksLimit: 5,
                                    font: { size: 10 },
                                    padding: 6
                                },
                                border: { display: false }
                            }
                        }
                    }
                });
            });
        }
    }));
</script>
@endscript
