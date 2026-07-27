const fs = require('fs');
let code = fs.readFileSync('resources/js/app.js', 'utf8');

const newMode = `
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

                ChartWithPlugins = chartJs.Chart;`;

code = code.replace('ChartWithPlugins = chartJs.Chart;', newMode);

const newTooltip = `                        tooltip: {
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
                                        timeStr = \` (\${day}/\${month} \${h}:\${m})\`;
                                    }
                                    return \`\${ctx.dataset.label}\${timeStr}: \${ctx.formattedValue}\${u}\`;
                                },
                            },
                        },`;

code = code.replace(/tooltip:\s*\{[\s\S]*?callbacks:\s*\{[\s\S]*?label:\s*\(ctx\)\s*=>\s*\{[\s\S]*?\},[\s\S]*?\},[\s\S]*?\},/, newTooltip);

// Update mode in options
code = code.replace(/interaction:\s*\{\s*mode:\s*'nearest',\s*axis:\s*'x',\s*intersect:\s*false\s*\}/, "interaction: { mode: 'nearestForEach', intersect: false }");

fs.writeFileSync('resources/js/app.js', code);
console.log('patched');
