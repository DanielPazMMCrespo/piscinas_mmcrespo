import './bootstrap';
import './push';
import './gsap-transitions';

// Listener para notificações de timers expirados
if (typeof Livewire !== 'undefined') {
    Livewire.on('timerExpirou', (event) => {
        const { poolNome, fase, tempoExcedido } = event;

        // Encontra a janela ou elemento de notificação do Filament
        const notificacao = document.querySelector('[data-notification-container]') ||
                          document.querySelector('[role="alert"]');

        if (notificacao && notificacao.parentElement) {
            // Cria elemento de notificação (fallback simples)
            const div = document.createElement('div');
            div.className = 'fi-notification fi-danger p-4 rounded text-sm bg-red-50 border border-red-200 text-red-700 mb-3';
            div.innerHTML = `
                <div class="flex items-center gap-2">
                    <span class="text-lg">⏱️</span>
                    <div>
                        <strong>${poolNome} - ${fase}</strong><br>
                        Timer expirou há <strong>${tempoExcedido}</strong>
                    </div>
                </div>
            `;
            notificacao.parentElement.insertBefore(div, notificacao);

            // Remove após 8 segundos
            setTimeout(() => div.remove(), 8000);
        }
    });
}

const reduzMovimento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Shared Chart.js class (with plugins) across all mmcChart instances on the page.
// Avoids duplicate plugin registration and duplicate dynamic imports.
let ChartWithPlugins = null;

window.mmcFormDirty = false;

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

                chartJs.Interaction.modes.nearestForEach = function(chart, e, options, useFinalPosition) {
                    const items = [];
                    for (let i = 0; i < chart.data.datasets.length; i++) {
                        const meta = chart.getDatasetMeta(i);
                        if (meta.hidden) continue;
                        let nearestItem = null;
                        let minDistance = Infinity;
                        for (let j = 0; j < meta.data.length; j++) {
                            const el = meta.data[j];
                            if (!el || typeof el.x !== 'number') continue;
                            const dist = Math.abs(el.x - e.x);
                            if (dist < minDistance) {
                                minDistance = dist;
                                nearestItem = { element: el, datasetIndex: i, index: j };
                            }
                        }
                        if (nearestItem && minDistance < 150) {
                            items.push(nearestItem);
                        }
                    }
                    return items;
                };

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
                    interaction: { mode: 'nearestForEach', intersect: false },
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
                                title: () => {
                                    return 'Valores Próximos';
                                },
                                label: (ctx) => {
                                    const axis = ctx.dataset.yAxisID === 'y' ? left : right;
                                    const u = axis.unidade ? ' ' + axis.unidade : '';
                                    let timeStr = '';
                                    if (ctx.raw && ctx.raw.x) {
                                        const d = new Date(ctx.raw.x);
                                        const day = String(d.getDate()).padStart(2, '0');
                                        const month = String(d.getMonth() + 1).padStart(2, '0');
                                        const h = String(d.getHours()).padStart(2, '0');
                                        const m = String(d.getMinutes()).padStart(2, '0');
                                        timeStr = ` (${day}/${month} ${h}:${m})`;
                                    }
                                    return `${ctx.dataset.label}${timeStr}: ${ctx.formattedValue}${u}`;
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



    window.Alpine.data('mmcEsquema', () => ({
        aberto: null,
        toggle(componente) {
            this.aberto = this.aberto === componente ? null : componente;
        },
    }));

    window.Alpine.data('countdownTimer', (statePath, defaultSeconds = 180) => ({
        statePath: statePath,
        initialSeconds: defaultSeconds,
        remainingSeconds: defaultSeconds,
        timer: null,
        isRunning: false,
        poolId: null,
        fase: null,
        alertado: false,
        endTime: null,
        _onVisibilityChange: null,

        // Deriva pool e fase do statePath (ex.: data.pools.4.timer_lavagem) para
        // agendar o push no servidor.
        parseContexto() {
            const sp = String(this.statePath);
            const comPiscina = sp.match(/pools\.(\d+)\.timer_(lavagem|enxaguamento)/);
            if (comPiscina) {
                this.poolId = parseInt(comPiscina[1], 10);
                this.fase = comPiscina[2];
                return;
            }
            const soFase = sp.match(/timer_(lavagem|enxaguamento)/);
            if (soFase) {
                this.fase = soFase[1];
            }
        },

        // Aviso local (aba viva): som + vibração + notificação. O push do servidor
        // cobre o caso da app fechada/bloqueada.
        avisarFim() {
            if (this.alertado) return;
            this.alertado = true;
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.001, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.3, ctx.currentTime + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
                osc.start();
                osc.stop(ctx.currentTime + 0.6);
            } catch (e) { /* sem áudio */ }

            if (navigator.vibrate) navigator.vibrate([300, 150, 300]);

            if (this.fase === 'lavagem' && this.poolId) {
                const inputLavagens = document.getElementById('numero_lavagens_filtro_' + this.poolId);
                if (inputLavagens) {
                    const currentVal = parseInt(inputLavagens.value || 0, 10);
                    if (!isNaN(currentVal)) {
                        inputLavagens.value = currentVal + 1;
                        inputLavagens.dispatchEvent(new Event('input', { bubbles: true }));
                        inputLavagens.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            }
        },

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
            this.parseContexto();
            const storageKey = 'mmc_timer_' + this.statePath;
            const saved = localStorage.getItem(storageKey);

            if (saved) {
                try {
                    const data = JSON.parse(saved);
                    this.initialSeconds = data.initialSeconds ?? defaultSeconds;
                    this.isRunning = data.isRunning ?? false;

                    if (this.isRunning && data.endTime) {
                        this.endTime = data.endTime;
                        this.remainingSeconds = Math.round((this.endTime - Date.now()) / 1000);
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

            // O ecrã bloqueado suspende o setInterval (o tick não corre em segundo
            // plano); ao desbloquear, resincronizar de imediato a partir do relógio
            // em vez de esperar pelo próximo tick (que retomaria do valor congelado).
            this._onVisibilityChange = () => {
                if (document.visibilityState === 'visible' && this.isRunning && this.endTime) {
                    this.remainingSeconds = Math.round((this.endTime - Date.now()) / 1000);
                    if (this.remainingSeconds <= 0) this.avisarFim();
                }
            };
            document.addEventListener('visibilitychange', this._onVisibilityChange);
        },

        saveState() {
            const storageKey = 'mmc_timer_' + this.statePath;
            const data = {
                initialSeconds: this.initialSeconds,
                remainingSeconds: this.remainingSeconds,
                isRunning: this.isRunning
            };
            if (this.isRunning) {
                data.endTime = this.endTime;
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
            // Só recalcula endTime se ainda não vier de uma restauração (init()) —
            // caso contrário perderíamos o instante de fim já persistido.
            if (!this.endTime) {
                this.endTime = Date.now() + (this.remainingSeconds * 1000);
            }
            if (this.remainingSeconds > 0) {
                this.alertado = false;
                window.mmcPush?.registarTimer(this.remainingSeconds, this.poolId, this.fase);
            }
            this.timer = setInterval(() => {
                // Recalcula sempre a partir do relógio (não decrementa por tick):
                // um ecrã bloqueado suspende o setInterval, e retomar a contagem
                // de onde parou ignoraria o tempo real decorrido.
                this.remainingSeconds = Math.round((this.endTime - Date.now()) / 1000);
                if (this.remainingSeconds <= 0) {
                    this.avisarFim();
                }
            }, 1000);
        },

        pauseTimer() {
            this.isRunning = false;
            this.endTime = null;
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
            window.mmcPush?.cancelarTimer(this.poolId, this.fase);
        },
        
        resetTimer() {
            this.pauseTimer();
            this.remainingSeconds = this.initialSeconds;
            this.alertado = false;
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
            if (this._onVisibilityChange) {
                document.removeEventListener('visibilitychange', this._onVisibilityChange);
            }
        }
    }));

    /**
     * Barra global fixa no topo com os timers de retrolavagem/enxaguamento
     * ativos, lidos diretamente do localStorage (mesma fonte que o
     * countdownTimer usa para persistir estado). Existe para que um timer
     * continue visível mesmo ao mudar de passo do wizard ou de piscina —
     * sem isto só se via o timer voltando ao passo/fieldset onde foi criado.
     * Clicável para navegar automaticamente ao fieldset onde o timer está.
     */
    window.Alpine.data('mmcTimerBar', () => ({
        timers: [],
        poll: null,
        notificadosTimers: new Map(), // Rastreia timers já notificados

        init() {
            this.refresh();
            this.poll = setInterval(() => this.refresh(), 1000);
        },

        refresh() {
            const ativos = [];
            const keysParaRemover = [];

            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (!key || !key.startsWith('mmc_timer_')) continue;

                let data;
                try {
                    data = JSON.parse(localStorage.getItem(key));
                } catch (e) {
                    continue;
                }
                if (!data || !data.isRunning || !data.endTime) continue;

                const statePath = key.replace('mmc_timer_', '');
                const match = statePath.match(/pools\.(\d+)\.timer_(lavagem|enxaguamento)/);
                const poolId = match ? parseInt(match[1], 10) : null;
                const fase = match ? match[2] : (statePath.match(/timer_(lavagem|enxaguamento)/) || [])[1];
                const remainingSeconds = Math.round((data.endTime - Date.now()) / 1000);
                const tempoExcedido = remainingSeconds < 0 ? Math.abs(remainingSeconds) : 0;

                // Se ultrapassou 30 min, remove automaticamente e envia notificação uma única vez
                if (tempoExcedido > 1800) {
                    if (!this.notificadosTimers.has(key)) {
                        this.notificadosTimers.set(key, true);
                        this.enviarNotificacao(
                            (poolId && window.__poolNomes?.[poolId]) || 'Piscina',
                            fase === 'enxaguamento' ? 'Enxaguamento' : 'Lavagem',
                            tempoExcedido
                        );
                    }
                    keysParaRemover.push(key);
                    continue;
                }

                ativos.push({
                    key,
                    statePath,
                    poolId,
                    poolNome: (poolId && window.__poolNomes?.[poolId]) || 'Piscina',
                    fase: fase === 'enxaguamento' ? 'Enxaguamento' : 'Lavagem',
                    remainingSeconds,
                    isExceeded: remainingSeconds < 0,
                });
            }

            // Remove timers expirados após iteração (evita problemas com índices)
            keysParaRemover.forEach(key => localStorage.removeItem(key));

            ativos.sort((a, b) => a.remainingSeconds - b.remainingSeconds);
            this.timers = ativos;
        },

        enviarNotificacao(poolNome, fase, tempoExcedidoSegundos) {
            // Formata tempo excedido em mm:ss
            const minutos = Math.floor(tempoExcedidoSegundos / 60);
            const segundos = tempoExcedidoSegundos % 60;
            const tempoFormatado = `${minutos}m ${segundos}s`;

            // Envia notificação Filament via Livewire (se disponível)
            if (typeof Livewire !== 'undefined') {
                Livewire.emit('timerExpirou', {
                    poolNome,
                    fase,
                    tempoExcedido: tempoFormatado,
                });
            }

            // Notificação browser (fallback)
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(`Timer Expirado - ${poolNome}`, {
                    body: `${fase} expirou há ${tempoFormatado}`,
                    icon: (window.__poolNomes && Object.keys(window.__poolNomes).length > 0)
                        ? '/images/logo-mmcrespo.png'
                        : undefined,
                    tag: `timer-${poolNome}-${fase}`,
                });
            }
        },

        formatar(segundos) {
            const isNeg = segundos < 0;
            const abs = Math.abs(segundos);
            const m = Math.floor(abs / 60).toString().padStart(2, '0');
            const s = (abs % 60).toString().padStart(2, '0');
            return `${isNeg ? '-' : ''}${m}:${s}`;
        },

        navegar(statePath, poolId) {
            // Lavagem e enxaguamento são passos distintos do Wizard. Antes de fazer
            // scroll é preciso trocar para o passo certo — senão o fieldset está num
            // passo escondido (display:none) e o scrollIntoView cai numa posição vazia.
            const fase = String(statePath).includes('timer_enxaguamento') ? 'enxaguamento' : 'lavagem';
            const stepLabel = fase === 'enxaguamento' ? 'Enxaguamento' : 'Lavagem filtros';

            this.irParaPasso(stepLabel);

            // Aguarda o Alpine terminar a transição do passo — em vez de um timeout
            // fixo, espera (com retries) até existir um fieldset realmente visível.
            this.focarFieldsetComRetry(poolId);
        },

        // Clica no header do passo do Wizard cujo label corresponde. Devolve true se
        // encontrou o botão do passo (e portanto vale a pena esperar pela transição).
        irParaPasso(stepLabel) {
            const norm = (s) => (s || '').replace(/\s+/g, ' ').trim();
            const wizardRoot = document.querySelector('[class*="fi-fo-wizard"], [class*="wizard"]') || document;
            const stepButton = Array.from(wizardRoot.querySelectorAll('button')).find((b) =>
                norm(b.getAttribute('aria-label')) === stepLabel || norm(b.textContent).includes(stepLabel)
            );
            if (stepButton) {
                stepButton.click();
                return true;
            }
            return false;
        },

        focarFieldsetComRetry(poolId, tentativa = 0) {
            const visivel = (el) => el && el.offsetParent !== null;
            const candidatos = [];

            document.querySelectorAll(`[data-pools-fieldset="${poolId}"]`).forEach((el) => candidatos.push(el));
            document.querySelectorAll('fieldset').forEach((fs) => {
                if (fs.querySelector(`[name*="pools.${poolId}"]`)) candidatos.push(fs);
            });
            const poolName = window.__poolNomes?.[poolId];
            if (poolName) {
                document.querySelectorAll('fieldset').forEach((fs) => {
                    if (fs.textContent.includes(poolName)) candidatos.push(fs);
                });
            }

            // Vários passos têm fieldsets da mesma piscina — só o do passo ativo está visível.
            const fieldset = candidatos.find(visivel);

            if (!fieldset) {
                // A transição do Wizard ainda não terminou (ou o passo ainda não montou
                // os fieldsets). Tenta de novo por até ~2s antes de desistir.
                if (tentativa < 20) {
                    setTimeout(() => this.focarFieldsetComRetry(poolId, tentativa + 1), 100);
                } else {
                    console.warn(`Fieldset visível não encontrado para pool ${poolId}`);
                }
                return;
            }

            fieldset.scrollIntoView({ behavior: 'smooth', block: 'center' });
            fieldset.classList.add('ring-2', 'ring-blue-500', 'ring-opacity-75');
            setTimeout(() => {
                fieldset.classList.remove('ring-2', 'ring-blue-500', 'ring-opacity-75');
            }, 2000);
        },

        destroy() {
            if (this.poll) clearInterval(this.poll);
        },
    }));

    /**
     * Gráfico de barras empilhadas (Consumo de Químicos por piscina/mês).
     * Reutiliza a mesma classe Chart.js já carregada por mmcChart quando
     * disponível, registando adicionalmente os controllers de barras.
     */
    window.Alpine.data('mmcBarChart', (initialPayload = null) => ({
        chart: null,
        _payload: initialPayload,

        async init() {
            const chartJs = await import('chart.js');
            if (!ChartWithPlugins) {
                ChartWithPlugins = chartJs.Chart;
            }
            ChartWithPlugins.register(
                chartJs.BarController,
                chartJs.BarElement,
                chartJs.CategoryScale,
                chartJs.LinearScale,
                chartJs.Legend,
                chartJs.Tooltip,
            );

            this.render();
        },

        render() {
            if (!this._payload || !this._payload.labels || !this.$refs.canvas) return;

            if (this.chart) {
                this.chart.destroy();
            }

            this.chart = new ChartWithPlugins(this.$refs.canvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: this._payload.labels,
                    datasets: this._payload.datasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true },
                    },
                    plugins: {
                        legend: { position: 'bottom' },
                    },
                },
            });
        },

        destroy() {
            if (this.chart) {
                this.chart.destroy();
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

// Registo NS: ao completar 3 dígitos num campo, avança automaticamente para o seguinte
const setupNsAutoAdvance = () => {
    const CAMPOS = ['ns_ph', 'ns_cloro_livre', 'ns_cloro_total', 'ns_temperatura'];

    document.addEventListener('input', (e) => {
        const el = e.target;
        if (el.tagName !== 'INPUT') return;

        const match = CAMPOS.find((campo) => el.id.startsWith(`${campo}_`));
        if (!match) return;

        const digitos = el.value.replace(/[^0-9]/g, '');
        if (digitos.length < 3) return;

        const indiceAtual = CAMPOS.indexOf(match);
        const proximoCampo = CAMPOS[indiceAtual + 1];
        if (!proximoCampo) return;

        const sufixo = el.id.slice(match.length);
        const proximoEl = document.getElementById(`${proximoCampo}${sufixo}`);
        if (proximoEl && proximoEl !== el) {
            proximoEl.focus();
            proximoEl.select();
        }
    });
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



// IndexedDB setup for Form Draft Photos
const dbName = 'DailyRecordDraftDB';
const storeName = 'photos';

const getDB = () => {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(dbName, 3);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains(storeName)) {
                db.createObjectStore(storeName);
            }
            if (!db.objectStoreNames.contains('offline_queue')) {
                db.createObjectStore('offline_queue', { keyPath: 'offline_id' });
            }
            if (!db.objectStoreNames.contains('form_drafts')) {
                db.createObjectStore('form_drafts');
            }
        };
        request.onsuccess = (e) => resolve(e.target.result);
        request.onerror = (e) => reject(e.target.error);
    });
};

const saveDraftToDB = async (key, draftObj) => {
    try {
        const db = await getDB();
        const tx = db.transaction('form_drafts', 'readwrite');
        tx.objectStore('form_drafts').put(draftObj, key);
    } catch (e) {
        console.error('Error saving draft to IndexedDB:', e);
    }
};

const getDraftFromDB = async (key) => {
    try {
        const db = await getDB();
        const tx = db.transaction('form_drafts', 'readonly');
        const req = tx.objectStore('form_drafts').get(key);
        return new Promise((res) => {
            req.onsuccess = () => res(req.result || null);
            req.onerror = () => res(null);
        });
    } catch (e) {
        return null;
    }
};

const savePhotoToDB = async (key, fileBlob) => {
    try {
        const db = await getDB();
        const tx = db.transaction(storeName, 'readwrite');
        tx.objectStore(storeName).put(fileBlob, key);
        await new Promise((res, rej) => {
            tx.oncomplete = res;
            tx.onerror = () => rej(tx.error);
        });
    } catch (e) {
        console.error('Error saving photo to IndexedDB:', e);
    }
};

const getPhotoFromDB = async (key) => {
    try {
        const db = await getDB();
        const tx = db.transaction(storeName, 'readonly');
        const req = tx.objectStore(storeName).get(key);
        return new Promise((res) => {
            req.onsuccess = () => res(req.result);
            req.onerror = () => res(null);
        });
    } catch (e) {
        console.error('Error reading photo from IndexedDB:', e);
        return null;
    }
};

const deletePhotoFromDB = async (key) => {
    try {
        const db = await getDB();
        const tx = db.transaction(storeName, 'readwrite');
        tx.objectStore(storeName).delete(key);
    } catch (e) {
        console.error('Error deleting photo from IndexedDB:', e);
    }
};

const clearAllPhotosFromDB = async () => {
    try {
        const db = await getDB();
        const tx = db.transaction(storeName, 'readwrite');
        tx.objectStore(storeName).clear();
    } catch (e) {
        console.error('Error clearing photos from IndexedDB:', e);
    }
};

// Offline Queue Management
const saveToOfflineQueue = async (payload) => {
    try {
        const db = await getDB();
        const tx = db.transaction('offline_queue', 'readwrite');
        const item = {
            offline_id: 'off_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6),
            data: payload,
            created_at: Date.now()
        };
        tx.objectStore('offline_queue').put(item);
        await new Promise((res, rej) => {
            tx.oncomplete = res;
            tx.onerror = () => rej(tx.error);
        });
        if (navigator.onLine) {
            syncOfflineRecords();
        }
        return item.offline_id;
    } catch (e) {
        console.error('Error saving to offline queue:', e);
        return null;
    }
};

const getOfflineQueue = async () => {
    try {
        const db = await getDB();
        const tx = db.transaction('offline_queue', 'readonly');
        const req = tx.objectStore('offline_queue').getAll();
        return new Promise((res) => {
            req.onsuccess = () => res(req.result || []);
            req.onerror = () => res([]);
        });
    } catch (e) {
        return [];
    }
};

const deleteFromOfflineQueue = async (offlineId) => {
    try {
        const db = await getDB();
        const tx = db.transaction('offline_queue', 'readwrite');
        tx.objectStore('offline_queue').delete(offlineId);
        await new Promise((res) => { tx.oncomplete = res; });
    } catch (e) {
        console.error('Error deleting from offline queue:', e);
    }
};

const syncOfflineRecords = async () => {
    if (!navigator.onLine) return;
    const items = await getOfflineQueue();
    if (!items || items.length === 0) {
        return;
    }

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch('/offline-sync/daily-records', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ records: items })
        });

        if (response.ok) {
            const result = await response.json();
            if (result && result.success && Array.isArray(result.synced_ids)) {
                for (const id of result.synced_ids) {
                    await deleteFromOfflineQueue(id);
                }
            }
        }
    } catch (e) {
        console.error('Offline sync failed:', e);
    }
};

// Voice Input Setup
const setupVoiceInput = () => {
    const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRec) return;

    document.addEventListener('dblclick', (e) => {
        const input = e.target;
        if (!input || (input.tagName !== 'INPUT' && input.tagName !== 'TEXTAREA')) return;
        if (input.type !== 'number' && input.type !== 'text' && input.tagName !== 'TEXTAREA') return;

        const recognition = new SpeechRec();
        recognition.lang = 'pt-PT';
        recognition.interimResults = false;
        recognition.maxAlternatives = 1;

        const originalBg = input.style.backgroundColor;
        input.style.backgroundColor = '#fef3c7';
        input.placeholder = '🎤 A ouvir... Fale agora';

        recognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript;
            let cleaned = transcript.trim();
            if (input.type === 'number' || input.id.includes('ph') || input.id.includes('cloro') || input.id.includes('temperatura')) {
                cleaned = cleaned.replace(/vírgula/gi, '.').replace(/ponto/gi, '.').replace(',', '.').replace(/[^0-9.]/g, '');
            }
            if (cleaned) {
                input.value = cleaned;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        };

        recognition.onend = () => { input.style.backgroundColor = originalBg; };
        recognition.onerror = () => { input.style.backgroundColor = originalBg; };

        try { recognition.start(); } catch (err) { /* já ativo */ }
    });
};


const getFieldKey = (filepondRoot) => {
    const input = filepondRoot.querySelector('input[name]');
    if (input) {
        return input.getAttribute('name') || input.id || null;
    }
    const wrapper = filepondRoot.closest('[id]');
    return wrapper ? wrapper.id : null;
};

const setupFormDraftPhotos = () => {
    document.addEventListener('FilePond:addfile', async (e) => {
        if (!window.location.pathname.includes('/daily-records/create')) return;

        const filepondRoot = e.target;
        const fieldKey = getFieldKey(filepondRoot);
        if (!fieldKey) return;

        const fileItem = e.detail.file;
        if (fileItem && fileItem.file instanceof Blob) {
            await savePhotoToDB(fieldKey, fileItem.file);
        }
    });

    document.addEventListener('FilePond:removefile', async (e) => {
        if (!window.location.pathname.includes('/daily-records/create')) return;

        const filepondRoot = e.target;
        const fieldKey = getFieldKey(filepondRoot);
        if (fieldKey) {
            await deletePhotoFromDB(fieldKey);
        }
    });
};

const restorePhotos = async () => {
    let attempts = 0;
    const attemptRestore = async () => {
        const filepondElements = document.querySelectorAll('.filepond--root');
        if (filepondElements.length === 0) {
            if (attempts < 30) {
                attempts++;
                setTimeout(attemptRestore, 100);
            }
            return;
        }

        let allFound = true;
        for (const el of filepondElements) {
            const pond = window.FilePond?.find(el);
            if (!pond) {
                allFound = false;
                break;
            }
        }

        if (!allFound && attempts < 30) {
            attempts++;
            setTimeout(attemptRestore, 100);
            return;
        }

        for (const el of filepondElements) {
            const pond = window.FilePond?.find(el);
            if (!pond) continue;

            const fieldKey = getFieldKey(el);
            if (!fieldKey) continue;

            const storedFile = await getPhotoFromDB(fieldKey);
            if (storedFile) {
                try {
                    const filename = storedFile.name || 'restored_image.jpg';
                    const fileToUpload = new File([storedFile], filename, { type: storedFile.type });
                    pond.removeFiles();
                    pond.addFile(fileToUpload);
                } catch (err) {
                    console.error('Error adding restored file to FilePond:', err, fieldKey);
                }
            }
        }
    };

    await attemptRestore();
};

// Seamless automatic draft restoration and banner feedback
const autoRestoreDraftAndShowBanner = async (formKey, component, draftData, savedAt) => {
    try {
        await component.set('data', draftData);
        if (window.Livewire && typeof component.$refresh === 'function') {
            component.$refresh();
        }
        await restorePhotos();
    } catch (err) {
        console.error('Error auto-restoring draft:', err);
    }

    if (document.getElementById('mmc-draft-banner')) return;

    const formattedTime = new Date(savedAt).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });

    const banner = document.createElement('div');
    banner.id = 'mmc-draft-banner';
    banner.className = 'w-full mb-4 bg-amber-500/10 border border-amber-500/30 text-amber-900 dark:text-amber-200 px-4 py-3 rounded-xl flex items-center justify-between shadow-sm transition-all animate-fade-in z-30';
    banner.innerHTML = `
        <div class="flex items-center gap-2.5 text-sm font-medium">
            <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Rascunho de registo restaurado (guardado às ${formattedTime}).</span>
        </div>
        <button id="discard-draft-banner-btn" type="button" class="text-xs font-semibold px-2.5 py-1 bg-amber-600/10 hover:bg-amber-600/20 text-amber-800 dark:text-amber-200 border border-amber-600/20 hover:border-amber-600/40 rounded-md transition-colors duration-150">
            Descartar rascunho
        </button>
    `;

    const formEl = document.querySelector('.fi-main form') || document.querySelector('form');
    if (formEl) {
        formEl.insertBefore(banner, formEl.firstChild);
    }

    document.getElementById('discard-draft-banner-btn')?.addEventListener('click', () => {
        localStorage.removeItem(formKey);
        Object.keys(localStorage).forEach(key => {
            if (key.startsWith('mmc_timer_')) {
                localStorage.removeItem(key);
            }
        });
        clearAllPhotosFromDB();
        banner.remove();
        window.location.reload();
    });
};

const askUserToRestoreDraft = (formKey, component, stored) => {
    if (document.getElementById('mmc-draft-modal')) return;

    const draftData = stored.data;
    const savedAt = stored.savedAt ?? 0;
    const savedStep = stored.step;
    const formattedTime = new Date(savedAt).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' });

    const modal = document.createElement('div');
    modal.id = 'mmc-draft-modal';
    modal.className = 'fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-all duration-300';
    modal.innerHTML = `
        <div class="w-full max-w-md bg-white dark:bg-gray-900 border border-gray-100 dark:border-gray-800 rounded-2xl shadow-2xl p-6 transition-all transform scale-100 duration-200">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-3 bg-amber-500/10 rounded-xl text-amber-600 dark:text-amber-400">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Recuperar registo anterior?</h3>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                Foi encontrado um rascunho de registo diário preenchido anteriormente às <span class="font-semibold text-gray-700 dark:text-gray-300">${formattedTime}</span>. Deseja recuperar as medições e fotografias para continuar a preencher?
            </p>
            <div class="flex items-center justify-end gap-3">
                <button id="mmc-draft-modal-discard-btn" type="button" class="px-4 py-2 text-sm font-semibold rounded-xl text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors duration-150 cursor-pointer">
                    Não, descartar
                </button>
                <button id="mmc-draft-modal-recover-btn" type="button" class="px-4 py-2 text-sm font-semibold rounded-xl text-white bg-amber-600 hover:bg-amber-500 shadow-sm transition-colors duration-150 cursor-pointer">
                    Sim, recuperar
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(modal);

    document.getElementById('mmc-draft-modal-recover-btn').addEventListener('click', async () => {
        modal.remove();
        const urlParams = new URLSearchParams(window.location.search);
        if (savedStep && urlParams.get('step') !== savedStep) {
            localStorage.setItem('mmc_restore_draft_on_load', 'true');
            urlParams.set('step', savedStep);
            window.location.href = window.location.pathname + '?' + urlParams.toString();
        } else {
            await autoRestoreDraftAndShowBanner(formKey, component, draftData, savedAt);
            if (typeof FilamentNotification !== 'undefined') {
                new FilamentNotification()
                    .title('Rascunho recuperado com sucesso!')
                    .success()
                    .send();
            }
        }
    });

    document.getElementById('mmc-draft-modal-discard-btn').addEventListener('click', async () => {
        localStorage.removeItem(formKey);
        Object.keys(localStorage).forEach(key => {
            if (key.startsWith('mmc_timer_')) {
                localStorage.removeItem(key);
            }
        });
        await clearAllPhotosFromDB();
        modal.remove();
        window.location.reload();
    });
};

const setupDirtyStateWarning = () => {
    if (!window.location.pathname.includes('/daily-records/create')) return;

    const formEl = document.querySelector('.fi-main form') || document.querySelector('form');
    if (formEl) {
        formEl.addEventListener('input', () => { window.mmcFormDirty = true; });
        formEl.addEventListener('change', () => { window.mmcFormDirty = true; });
        formEl.addEventListener('click', (e) => {
            const target = e.target.closest('button, input, select, [role="switch"]');
            if (target) {
                if (target.type === 'submit' || target.innerText.includes('Criar') || target.innerText.includes('Confirmar')) {
                    window.mmcFormDirty = false;
                } else {
                    window.mmcFormDirty = true;
                }
            }
        });
    }

    window.addEventListener('beforeunload', (e) => {
        if (window.mmcFormDirty) {
            e.preventDefault();
            e.returnValue = 'Tem alterações não guardadas no registo diário. Tem a certeza que deseja sair?';
            return e.returnValue;
        }
    });

    document.addEventListener('livewire:navigate', (e) => {
        if (window.mmcFormDirty) {
            const confirmLeave = confirm('Tem alterações não guardadas no registo diário. Tem a certeza que deseja sair?');
            if (!confirmLeave) {
                e.preventDefault();
            } else {
                window.mmcFormDirty = false;
            }
        }
    });
};

// Auto-save form draft in localStorage & IndexedDB for Daily Record creation
const DRAFT_TTL_MS = 45 * 60 * 1000;

const setupFormDraft = () => {
    if (!window.location.pathname.includes('/daily-records/create')) return;

    const findAndRestore = async () => {
        // Find the main Livewire component container that actually contains the form
        const mainComponentEl = Array.from(document.querySelectorAll('[wire\\:id]'))
            .find(el => el.querySelector('form') !== null);

        if (!mainComponentEl) {
            setTimeout(findAndRestore, 100);
            return;
        }

        const componentId = mainComponentEl.getAttribute('wire:id');
        const component = window.Livewire ? window.Livewire.find(componentId) : null;

        if (!component) {
            setTimeout(findAndRestore, 100);
            return;
        }

        const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');

        // 1. Try reading draft from localStorage first, then fallback to IndexedDB
        let stored = null;
        const raw = localStorage.getItem(formKey);
        if (raw) {
            try { stored = JSON.parse(raw); } catch (e) {}
        }
        if (!stored) {
            stored = await getDraftFromDB(formKey);
        }

        const forceRestore = localStorage.getItem('mmc_restore_draft_on_load') === 'true';

        if (stored && stored.data) {
            const draftData = stored.data;
            const savedAt = stored.savedAt ?? 0;
            const expired = Date.now() - savedAt > DRAFT_TTL_MS;

            if (expired) {
                localStorage.removeItem(formKey);
            } else if (draftData && Object.keys(draftData).length > 0 && !window.__mmcDraftRestored) {
                if (forceRestore) {
                    localStorage.removeItem('mmc_restore_draft_on_load');
                    window.__mmcDraftRestored = true;
                    await autoRestoreDraftAndShowBanner(formKey, component, draftData, savedAt);
                } else {
                    window.__mmcDraftRestored = true;
                    askUserToRestoreDraft(formKey, component, stored);
                }
            }
        }

        const saveDraft = async () => {
            const currentData = component.get('data');
            if (currentData && Object.keys(currentData).length > 0) {
                const urlParams = new URLSearchParams(window.location.search);
                const currentStep = urlParams.get('step');
                const payload = { data: currentData, step: currentStep, savedAt: Date.now() };
                localStorage.setItem(formKey, JSON.stringify(payload));
                await saveDraftToDB(formKey, payload);
            }
        };

        // 2. Save on every keystroke, change, and click event
        let debounceTimeout;
        const triggerSave = () => {
            clearTimeout(debounceTimeout);
            debounceTimeout = setTimeout(saveDraft, 400);
        };

        document.addEventListener('input', triggerSave);
        document.addEventListener('change', triggerSave);
        document.addEventListener('blur', triggerSave, true);
        document.addEventListener('click', triggerSave);

        // 3. Fallback periodic save every 2 seconds
        setInterval(saveDraft, 2000);
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
    setupNsAutoAdvance();
    setupHeaderLayout();
    setupFormDraft();
    setupFormDraftPhotos();
    setupDirtyStateWarning();
    setupGlobalImageLightbox();
    setupVoiceInput();
    syncOfflineRecords();

    window.addEventListener('online', syncOfflineRecords);

    // Intercetação do botão Guardar no modo Offline
    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('button');
        if (!btn || navigator.onLine) return;
        if (window.location.pathname.includes('/daily-records/create') && (btn.innerText.includes('Criar') || btn.innerText.includes('Confirmar e guardar'))) {
            e.preventDefault();
            e.stopPropagation();
            const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');
            const draftStr = localStorage.getItem(formKey);
            if (draftStr) {
                try {
                    const draft = JSON.parse(draftStr);
                    if (draft && draft.data) {
                        await saveToOfflineQueue(draft.data);
                        alert('⚡ Modo Offline: O registo foi guardado localmente e será sincronizado automaticamente quando a internet for restaurada!');
                    }
                } catch (err) {
                    console.error('Offline save error:', err);
                }
            } else {
                alert('⚡ Modo Offline: Por favor preencha os campos de medições antes de guardar.');
            }
        }
    }, true);
};

document.addEventListener('DOMContentLoaded', mmcSetup);
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    mmcSetup();
}

// Global Livewire 3 init hooks
document.addEventListener('livewire:init', () => {
    // Watch Livewire request lifecycle to auto-save drafts on server updates (e.g. toggles, selections)
    Livewire.hook('request', ({ component, respond }) => {
        if (component && component.name && component.name.includes('create-daily-record')) {
            respond(() => {
                const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');
                try {
                    const currentData = component.get('data');
                    if (currentData) {
                        const urlParams = new URLSearchParams(window.location.search);
                        const currentStep = urlParams.get('step');
                        localStorage.setItem(formKey, JSON.stringify({ data: currentData, step: currentStep, savedAt: Date.now() }));
                    }
                } catch (e) {
                    console.error('Error saving daily record draft:', e);
                }
            });
        }
    });

    // Clear draft and all active timers when dailyRecordSaved event is emitted
    Livewire.on('dailyRecordSaved', () => {
        window.mmcFormDirty = false;
        const formKey = 'daily_record_form_draft_' + (window.__userId ?? 'anon');
        localStorage.removeItem(formKey);
        Object.keys(localStorage).forEach(key => {
            if (key.startsWith('mmc_timer_')) {
                localStorage.removeItem(key);
            }
        });
        clearAllPhotosFromDB();
        window.mmcPush?.cancelarTodosTimers();
    });
});

// Setup form draft on Livewire SPA page transitions
document.addEventListener('livewire:navigated', () => {
    setupFormDraft();
    setupDirtyStateWarning();
    syncOfflineRecords();
});
