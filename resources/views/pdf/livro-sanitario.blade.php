{{--
    Livro de Registo Sanitário — CN 14/DA (DGS 2009) — MMCrespo
    Template para dompdf (A4 landscape). Limitações do dompdf respeitadas:
    CSS embebido em <style>, sem flex/grid, fonte DejaVu Sans (acentos PT),
    repetição de cabeçalho de tabela via <thead>, preto e branco.
    SVG inline suportado via php-svg-lib (sem rgba, sem JS).

    Variáveis esperadas:
      $instalacao (App\Models\Installation)
      $seccoes    (array de ['piscina' => Pool, 'registos' => Collection<DailyRecord>, 'controlador' => Collection])
      $inicio, $fim, $emitidoEm (Carbon)  $emitidoPor (?string)
--}}
@php
    $colunasVisiveis = $colunasVisiveis ?? [
        'hora', 'tecnico', 'ph', 'cloro_livre', 'cloro_total',
        'cloro_combinado', 'temperatura', 'transparencia',
        'contador_valor', 'bomba_tanque', 'renovacao_agua',
        'caleira_feita', 'pressao_filtro', 'lavagens_filtro',
        'banhistas', 'acao_corretiva', 'observacoes', 'conforme'
    ];
    $seccoesVisiveis = $seccoesVisiveis ?? [
        'mostrar_resumo', 'mostrar_controlador_grafico',
        'mostrar_controlador_tabela', 'mostrar_assinaturas',
        'mostrar_nota_legal'
    ];
    $modo = $modo ?? 'todos';
@endphp
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>Livro de Registo Sanitário — CN 14/DA</title>
    <style>
        @page {
            margin: 96px 36px 60px 36px; /* topo reserva espaço ao cabeçalho fixo */
        }

        * { box-sizing: border-box; }

        body {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 8px;
            color: #000;
            margin: 0;
        }

        /* Cabeçalho fixo: repete em todas as páginas */
        .cabecalho-fixo {
            position: fixed;
            top: -78px;
            left: 0;
            right: 0;
            border-bottom: 1.5px solid #000;
            padding-bottom: 4px;
        }
        .cabecalho-fixo .titulo {
            font-size: 12px;
            font-weight: bold;
            margin: 0 0 2px 0;
        }
        .cabecalho-fixo .subtitulo {
            font-size: 8px;
            margin: 0;
        }
        .cabecalho-fixo .marca {
            position: absolute;
            top: 0;
            right: 0;
            text-align: right;
            font-size: 9px;
            font-weight: bold;
        }
        .cabecalho-fixo .marca .emissao {
            font-size: 7px;
            font-weight: normal;
        }

        /* Secção por piscina */
        .seccao-piscina { margin-bottom: 14px; }
        .quebra { page-break-before: always; }

        .info-piscina {
            font-size: 9px;
            font-weight: bold;
            border: 1px solid #000;
            background: #eee;
            padding: 4px 6px;
            margin: 0 0 4px 0;
        }
        .info-piscina .detalhe { font-weight: normal; font-size: 8px; }

        table.registos {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            word-wrap: break-word;
        }
        table.registos thead { display: table-header-group; }
        table.registos th,
        table.registos td {
            border: 0.5px solid #000;
            padding: 2.5px 3px;
            vertical-align: middle;
        }
        table.registos th {
            background: #ddd;
            font-size: 7px;
            text-transform: uppercase;
            text-align: center;
        }
        table.registos td { text-align: center; }
        table.registos td.texto { text-align: left; }
        table.registos tr { page-break-inside: avoid; }

        .fora-gama { font-weight: bold; }   /* valor fora dos limites legais (P&B: negrito) */
        .nao-conforme { font-weight: bold; }

        .resumo {
            margin-top: 4px;
            font-size: 8px;
            border: 1px solid #000;
            padding: 3px 6px;
        }

        .sem-registos {
            border: 1px solid #000;
            padding: 14px;
            text-align: center;
            font-size: 10px;
            font-style: italic;
        }

        /* Secção do controlador Hanna */
        .controlador-titulo {
            font-size: 8px;
            font-weight: bold;
            margin: 10px 0 3px 0;
            padding: 3px 6px;
            background: #f0f0f0;
            border: 0.5px solid #999;
        }
        .controlador-subtitulo {
            font-size: 7px;
            font-weight: normal;
            margin-left: 4px;
        }
        .svg-wrap {
            margin: 3px 0 5px 0;
            page-break-inside: avoid;
        }

        table.controlador th {
            background: #e8e8e8;
        }

        /* Assinaturas e nota legal (fim do documento) */
        .assinaturas {
            margin-top: 28px;
            width: 100%;
            page-break-inside: avoid;
        }
        .assinaturas td {
            width: 50%;
            padding: 0 24px;
            text-align: center;
            vertical-align: bottom;
        }
        .assinaturas .linha {
            border-top: 1px solid #000;
            margin-top: 36px;
            padding-top: 3px;
            font-size: 8px;
        }
        .assinaturas .data-assinatura { font-size: 7px; margin-top: 6px; }

        .nota-legal {
            margin-top: 12px;
            font-size: 7px;
            text-align: center;
            border-top: 0.5px solid #000;
            padding-top: 4px;
        }
    </style>
</head>
<body>

    {{-- Cabeçalho repetido em todas as páginas --}}
    <div class="cabecalho-fixo">
        <p class="titulo">Livro de Registo Sanitário — CN 14/DA (DGS 2009)</p>
        <p class="subtitulo">
            Instalação: <strong>{{ $instalacao->name }}</strong>
            @if (count($seccoes) === 1)
                &nbsp;|&nbsp; Piscina: <strong>{{ $seccoes[0]['piscina']->name }}</strong>
            @else
                &nbsp;|&nbsp; Piscinas: <strong>todas ({{ count($seccoes) }})</strong>
            @endif
            &nbsp;|&nbsp; Período: <strong>{{ $inicio->format('d/m/Y') }} a {{ $fim->format('d/m/Y') }}</strong>
        </p>
        <div class="marca">
            MMCrespo
            <div class="emissao">
                Emitido em {{ $emitidoEm->format('d/m/Y H:i') }}
                @if (! empty($emitidoPor)) por {{ $emitidoPor }} @endif
            </div>
        </div>
    </div>

    @foreach ($seccoes as $indice => $seccao)
        @php
            /** @var \App\Models\Pool $piscina */
            $piscina = $seccao['piscina'];
            /** @var \Illuminate\Support\Collection<int, \App\Models\DailyRecord> $registos */
            $registos = $seccao['registos'];
            /** @var \Illuminate\Support\Collection $controlador */
            $controlador = $seccao['controlador'];
            /** @var \Illuminate\Support\Collection<int, \App\Models\OperationalAction> $acoesOperacionais */
            $acoesOperacionais = $seccao['acoes_operacionais'] ?? collect();

            // Conformidade global por registo: pH, cloro livre, cloro combinado
            // (limites legais do model) e temperatura (limites próprios da piscina).
            // Chave por spl_object_id, não por id: em modo "média diária" os
            // registos são objetos DailyRecord mock, cujo id nunca é definido
            // (ficaria null para todos os dias e colapsaria a mesma chave).
            $conformidade = $registos->mapWithKeys(fn ($registo) => [
                spl_object_id($registo) => $registo->phConforme()
                    && $registo->cloroLivreConforme()
                    && $registo->cloroCombinadoConforme()
                    && $registo->temperaturaConforme(),
            ]);

            $totalRegistos = $registos->count();
            $naoConformes = $conformidade->filter(fn ($ok) => ! $ok)->count();
            $percentagem = $totalRegistos > 0
                ? round((($totalRegistos - $naoConformes) / $totalRegistos) * 100, 1)
                : null;

            $phMin = \App\Models\DailyRecord::getPhMin();
            $phMax = \App\Models\DailyRecord::getPhMax();
        @endphp

        <div class="seccao-piscina {{ $indice > 0 ? 'quebra' : '' }}">
            <p class="info-piscina">
                Piscina: {{ $piscina->name }}
                <span class="detalhe">
                    — Tipo: {{ ucfirst((string) ($piscina->type ?? '—')) }}
                    | Volume: {{ $piscina->volume !== null ? number_format((float) $piscina->volume, 0, ',', ' ') . ' m³' : '—' }}
                    @if ($piscina->temp_min !== null && $piscina->temp_max !== null)
                        | Temperatura de referência: {{ $piscina->temp_min }}–{{ $piscina->temp_max }} °C
                    @endif
                </span>
            </p>

            @if ($registos->isEmpty())
                <div class="sem-registos">
                    Sem registos diários no período de {{ $inicio->format('d/m/Y') }} a {{ $fim->format('d/m/Y') }}
                    para a piscina "{{ $piscina->name }}".
                </div>
            @else
                <table class="registos">
                    <thead>
                        <tr>
                            <th>Data</th>
                            @if ($modo === 'todos' && in_array('hora', $colunasVisiveis)) <th>Hora</th> @endif
                            @if (in_array('tecnico', $colunasVisiveis)) <th>Técnico</th> @endif
                            @if (in_array('ph', $colunasVisiveis)) <th>pH</th> @endif
                            @if (in_array('cloro_livre', $colunasVisiveis)) <th>Cl. Livre (mg/L)</th> @endif
                            @if (in_array('cloro_total', $colunasVisiveis)) <th>Cl. Total (mg/L)</th> @endif
                            @if (in_array('cloro_combinado', $colunasVisiveis)) <th>Cl. Combinado (mg/L)</th> @endif
                            @if (in_array('temperatura', $colunasVisiveis)) <th>Temp. (°C)</th> @endif
                            @if (in_array('transparencia', $colunasVisiveis)) <th>Transp.</th> @endif
                            @if (in_array('contador_valor', $colunasVisiveis)) <th>Contador (m³)</th> @endif
                            @if (in_array('bomba_tanque', $colunasVisiveis)) <th>Bomba / Tanque</th> @endif
                            @if (in_array('renovacao_agua', $colunasVisiveis)) <th>Renov. Água</th> @endif
                            @if (in_array('caleira_feita', $colunasVisiveis)) <th>Caleira</th> @endif
                            @if (in_array('pressao_filtro', $colunasVisiveis)) <th>Pressão (bar)</th> @endif
                            @if (in_array('lavagens_filtro', $colunasVisiveis)) <th>Lavagens</th> @endif
                            @if (in_array('banhistas', $colunasVisiveis)) <th>Banhistas</th> @endif
                            @if (in_array('acao_corretiva', $colunasVisiveis)) <th>Ação corretiva</th> @endif
                            @if (in_array('observacoes', $colunasVisiveis)) <th>Observações</th> @endif
                            @if (in_array('conforme', $colunasVisiveis)) <th>Conforme</th> @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($registos as $registo)
                            @php
                                $conforme = $conformidade[spl_object_id($registo)] ?? true;
                                // Negrito apenas quando o valor existe E está fora de gama.
                                $phFora = $registo->ph_efetivo !== null && ! $registo->phConforme();
                                $clFora = $registo->cloro_livre_efetivo !== null && ! $registo->cloroLivreConforme();
                                $combFora = $registo->cloro_total_efetivo !== null
                                    && $registo->cloro_livre_efetivo !== null
                                    && ! $registo->cloroCombinadoConforme();
                                $tempFora = $registo->temperatura_efetivo !== null && ! $registo->temperaturaConforme();
                                // Ação corretiva vem das adições de químicos (modo média usa o
                                // atributo do mock; modo "todos" concatena as adições do registo).
                                $acaoCorretiva = $registo->acao_corretiva
                                    ?? ($registo->relationLoaded('adicoes')
                                        ? ($registo->adicoes->pluck('acao_corretiva')->filter()->unique()->implode('; ') ?: null)
                                        : null);
                                $lavouFiltro = $registo->filtro_faz_retrolavagem || ($registo->numero_lavagens_filtro !== null && $registo->numero_lavagens_filtro > 0);
                                $fezRenovacao = $registo->renovacao_agua || $registo->agua_modo === 'on_com_agua' || ($registo->agua_modo === 'auto_com_agua' && $lavouFiltro);
                            @endphp
                            <tr>
                                <td>{{ $registo->registado_em?->format('d/m/Y') ?? '—' }}</td>
                                @if ($modo === 'todos' && in_array('hora', $colunasVisiveis))
                                    <td>
                                        {{ $registo->registado_em?->format('H:i') ?? '—' }}
                                        @if ($registo->e_correcao)
                                            <br><span style="font-size: 6px;">(correção)</span>
                                        @endif
                                    </td>
                                @endif
                                @if (in_array('tecnico', $colunasVisiveis))
                                    <td class="texto">{{ $registo->utilizador?->name ?? '—' }}</td>
                                @endif
                                @if (in_array('ph', $colunasVisiveis))
                                    <td>
                                        @if ($registo->ph_efetivo !== null)
                                            <span @class(['fora-gama' => $phFora])>{{ $registo->ph_efetivo }}</span>
                                        @else — @endif
                                    </td>
                                @endif
                                @if (in_array('cloro_livre', $colunasVisiveis))
                                    <td>
                                        @if ($registo->cloro_livre_efetivo !== null)
                                            <span @class(['fora-gama' => $clFora])>{{ $registo->cloro_livre_efetivo }}</span>
                                        @else — @endif
                                    </td>
                                @endif
                                @if (in_array('cloro_total', $colunasVisiveis))
                                    <td>{{ $registo->cloro_total_efetivo ?? '—' }}</td>
                                @endif
                                @if (in_array('cloro_combinado', $colunasVisiveis))
                                    <td>
                                        @if ($registo->cloro_total_efetivo !== null && $registo->cloro_livre_efetivo !== null)
                                            <span @class(['fora-gama' => $combFora])>{{ number_format($registo->cloro_combinado, 2) }}</span>
                                        @else — @endif
                                    </td>
                                @endif
                                @if (in_array('temperatura', $colunasVisiveis))
                                    <td>
                                        @if ($registo->temperatura_efetivo !== null)
                                            <span @class(['fora-gama' => $tempFora])>{{ $registo->temperatura_efetivo }}</span>
                                        @else — @endif
                                    </td>
                                @endif
                                @if (in_array('transparencia', $colunasVisiveis))
                                    <td>{{ in_array($piscina->name, ['Lazer', 'Competição', 'Infantil']) ? 'Conforme' : ($registo->transparencia ?? '—') }}</td>
                                @endif
                                @if (in_array('contador_valor', $colunasVisiveis))
                                    <td>{{ $registo->contador_valor !== null ? number_format((float) $registo->contador_valor, 2, ',', ' ') : '—' }}</td>
                                @endif
                                @if (in_array('bomba_tanque', $colunasVisiveis))
                                    <td>
                                        @if (in_array($piscina->name, ['Lazer', 'Competição', 'Infantil']))
                                            Conforme
                                        @else
                                            {{ $registo->bomba_ferrada === null ? '—' : ($registo->bomba_ferrada ? '✓' : '✗') }}
                                            /
                                            {{ $registo->tanque_ok === null ? '—' : ($registo->tanque_ok ? '✓' : '✗') }}
                                        @endif
                                    </td>
                                @endif
                                @if (in_array('renovacao_agua', $colunasVisiveis))
                                    <td>{{ $fezRenovacao ? '✓' : ($registo->renovacao_agua === false ? '✗' : '—') }}</td>
                                @endif
                                @if (in_array('caleira_feita', $colunasVisiveis))
                                    <td>{{ $registo->caleira_feita === null ? '—' : ($registo->caleira_feita ? '✓' : '✗') }}</td>
                                @endif
                                @if (in_array('pressao_filtro', $colunasVisiveis))
                                    <td>{{ $registo->pressao_filtro !== null ? number_format((float) $registo->pressao_filtro, 2, ',', '') : '—' }}</td>
                                @endif
                                @if (in_array('lavagens_filtro', $colunasVisiveis))
                                    <td>
                                        @if ($registo->numero_lavagens_filtro !== null && $registo->numero_lavagens_filtro > 0)
                                            {{ $registo->numero_lavagens_filtro }}
                                        @elseif ($registo->filtro_faz_retrolavagem)
                                            ✓
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                                @if (in_array('banhistas', $colunasVisiveis))
                                    <td>{{ $registo->banhistas ?? '—' }}</td>
                                @endif
                                @if (in_array('acao_corretiva', $colunasVisiveis))
                                    <td class="texto">{{ $acaoCorretiva !== null && $acaoCorretiva !== '' ? \Illuminate\Support\Str::limit((string) $acaoCorretiva, 70) : '—' }}</td>
                                @endif
                                @if (in_array('observacoes', $colunasVisiveis))
                                    <td class="texto">{{ filled($registo->observacoes) ? \Illuminate\Support\Str::limit((string) $registo->observacoes, 80) : '—' }}</td>
                                @endif
                                @if (in_array('conforme', $colunasVisiveis))
                                    <td>
                                        @if ($conforme)
                                            ✓
                                        @else
                                            <span class="nao-conforme">✗</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if (in_array('mostrar_resumo', $seccoesVisiveis))
                <p class="resumo">
                    <strong>Resumo — {{ $piscina->name }}:</strong>
                    {{ $totalRegistos }} {{ $totalRegistos === 1 ? 'registo' : 'registos' }} no período
                    | Não conformidades: <strong>{{ $naoConformes }}</strong>
                    | Conformidade: <strong>{{ number_format((float) $percentagem, 1, ',', '') }}%</strong>
                </p>
                @endif
            @endif

            {{-- ================================================================
                 Secção do Controlador Hanna BL132 — Leituras Automáticas
                 Complemento informativo; não substitui o registo manual obrigatório.
                 ================================================================ --}}
            @if ($controlador->isNotEmpty() && (in_array('mostrar_controlador_grafico', $seccoesVisiveis) || in_array('mostrar_controlador_tabela', $seccoesVisiveis)))
                @php
                    // ---- Dados para o gráfico SVG de pH ----
                    // Dimensões da área de plot (em px, adaptadas ao A4 landscape dompdf)
                    $svgW  = 700;
                    $svgH  = 90;
                    $pL    = 28;   // left padding (espaço para label Y)
                    $pR    = $svgW - 6;
                    $pT    = 6;    // top padding
                    $pB    = $svgH - 14; // bottom padding (espaço para labels X)
                    $pW    = $pR - $pL;
                    $pH    = $pB - $pT;  // altura da área de plot

                    $phRangeMin = 5.8;
                    $phRangeMax = 9.2;

                    // Y: pH alto = topo da área (Y menor no SVG)
                    $yFor = fn (float $v): float => round(
                        $pT + ($phRangeMax - $v) / ($phRangeMax - $phRangeMin) * $pH,
                        1
                    );

                    // Banda verde (conformidade CN 14/DA): pH 6.9–8.0
                    $bandYTop = $yFor($phMax); // 8.0 → mais perto do topo
                    $bandYBot = $yFor($phMin); // 6.9 → mais perto do fundo

                    // Todas as datas do período (para X uniforme)
                    $periodoTotal = \Carbon\CarbonPeriod::create(
                        $inicio->copy()->startOfDay(),
                        '1 day',
                        $fim->copy()->startOfDay()
                    );
                    $allDays = collect($periodoTotal)->values();
                    $numDays = $allDays->count();
                    $xStep   = $numDays > 1 ? $pW / ($numDays - 1) : $pW;
                    $xFor    = fn (int $i): float => round($pL + $i * $xStep, 1);

                    // Índice por data
                    $byDay = $controlador->keyBy('dia');

                    // Construir path SVG com M/L (M = início de segmento, para lidar com gaps)
                    $pathParts  = [];
                    $prevHasVal = false;
                    foreach ($allDays as $i => $day) {
                        $leitura = $byDay->get($day->format('Y-m-d'));
                        $phVal = $leitura ? ($leitura->ph_avg ?? $leitura->ph ?? null) : null;
                        if ($phVal !== null) {
                            $x = $xFor($i);
                            $y = $yFor((float) $phVal);
                            $pathParts[] = ($prevHasVal ? 'L' : 'M') . " $x $y";
                            $prevHasVal = true;
                        } else {
                            $prevHasVal = false;
                        }
                    }
                    $pathD = implode(' ', $pathParts);

                    // Labels do eixo X: mostrar no máx. 8 datas para não amontoar
                    $labelStep  = max(1, (int) ceil($numDays / 8));

                    // Linhas horizontais de referência para Y
                    $yRefs = [6.0, 6.9, 7.5, 8.0, 8.5, 9.0];
                @endphp

                <p class="controlador-titulo">
                    Controlador Hanna BL132 — Leituras Automáticas
                    <span class="controlador-subtitulo">(complemento informativo; não substitui o registo manual obrigatório CN 14/DA)</span>
                </p>

                {{-- Gráfico SVG de pH — renderizado pelo php-svg-lib do dompdf --}}
                @if ($pathD !== '' && in_array('mostrar_controlador_grafico', $seccoesVisiveis))
                    <div class="svg-wrap">
                        <svg width="{{ $svgW }}" height="{{ $svgH }}" xmlns="http://www.w3.org/2000/svg">

                            {{-- Banda de conformidade pH 6.9–8.0 (cinzento claro em P&B) --}}
                            <rect
                                x="{{ $pL }}" y="{{ $bandYTop }}"
                                width="{{ $pW }}" height="{{ $bandYBot - $bandYTop }}"
                                fill="#cceecc" fill-opacity="0.6" stroke="none"
                            />

                            {{-- Linhas de referência horizontais --}}
                            @foreach ($yRefs as $phRef)
                                @php $yRef = $yFor($phRef); @endphp
                                <line
                                    x1="{{ $pL }}" y1="{{ $yRef }}"
                                    x2="{{ $pR }}" y2="{{ $yRef }}"
                                    stroke="#cccccc" stroke-width="0.4"
                                />
                                <text x="{{ $pL - 2 }}" y="{{ $yRef + 2 }}"
                                      font-size="5" text-anchor="end" fill="#555">{{ $phRef }}</text>
                            @endforeach

                            {{-- Linha de limite mínimo (pH 6.9) --}}
                            <line
                                x1="{{ $pL }}" y1="{{ $bandYBot }}"
                                x2="{{ $pR }}" y2="{{ $bandYBot }}"
                                stroke="#888888" stroke-width="0.6" stroke-dasharray="2,2"
                            />
                            {{-- Linha de limite máximo (pH 8.0) --}}
                            <line
                                x1="{{ $pL }}" y1="{{ $bandYTop }}"
                                x2="{{ $pR }}" y2="{{ $bandYTop }}"
                                stroke="#888888" stroke-width="0.6" stroke-dasharray="2,2"
                            />

                            {{-- Linha de pH do controlador --}}
                            <path
                                d="{{ $pathD }}"
                                fill="none"
                                stroke="#1a5fa8"
                                stroke-width="1.4"
                                stroke-linejoin="round"
                                stroke-linecap="round"
                            />

                            {{-- Pontos nos dias com leitura --}}
                             @foreach ($allDays as $i => $day)
                                @php
                                    $leitura = $byDay->get($day->format('Y-m-d'));
                                    $phVal = $leitura ? ($leitura->ph_avg ?? $leitura->ph ?? null) : null;
                                @endphp
                                @if ($phVal !== null)
                                    @php
                                        $cx    = $xFor($i);
                                        $cy    = $yFor((float) $phVal);
                                        $fora  = (float) $phVal < $phMin || (float) $phVal > $phMax;
                                    @endphp
                                    <circle
                                        cx="{{ $cx }}" cy="{{ $cy }}" r="2"
                                        fill="{{ $fora ? '#cc2222' : '#1a5fa8' }}"
                                    />
                                @endif
                            @endforeach

                            {{-- Labels do eixo X --}}
                            @foreach ($allDays as $i => $day)
                                @if ($i % $labelStep === 0 || $i === $numDays - 1)
                                    <text
                                        x="{{ $xFor($i) }}" y="{{ $svgH - 3 }}"
                                        font-size="5" text-anchor="middle" fill="#333"
                                    >{{ $day->format('d/m') }}</text>
                                @endif
                            @endforeach

                            {{-- Label do eixo Y --}}
                            <text
                                x="3" y="{{ ($pT + $pB) / 2 }}"
                                font-size="5.5" fill="#333"
                                transform="rotate(-90, 3, {{ ($pT + $pB) / 2 }})"
                                text-anchor="middle"
                            >pH</text>

                            {{-- Legenda --}}
                            <rect x="{{ $pR - 120 }}" y="{{ $pT }}" width="8" height="5" fill="#cceecc" fill-opacity="0.6" />
                            <text x="{{ $pR - 109 }}" y="{{ $pT + 4.5 }}" font-size="5" fill="#333">Conforme ({{ $phMin }}–{{ $phMax }})</text>
                            <line x1="{{ $pR - 120 }}" y1="{{ $pT + 11 }}" x2="{{ $pR - 112 }}" y2="{{ $pT + 11 }}" stroke="#1a5fa8" stroke-width="1.4"/>
                            <circle cx="{{ $pR - 116 }}" cy="{{ $pT + 11 }}" r="2" fill="#1a5fa8"/>
                            <text x="{{ $pR - 109 }}" y="{{ $pT + 13 }}" font-size="5" fill="#333">pH controlador (médias diárias)</text>
                        </svg>
                    </div>
                @endif

                {{-- Tabela de médias diárias do controlador --}}
                @if (in_array('mostrar_controlador_tabela', $seccoesVisiveis))
                @if (!isset($controladorModo) || $controladorModo === 'media_diaria')
                    <table class="registos controlador">
                        <thead>
                            <tr>
                                <th style="width: 9%;">Data</th>
                                <th style="width: 7%;">Leituras/dia</th>
                                <th style="width: 8%;">pH Médio</th>
                                <th style="width: 8%;">pH Mínimo</th>
                                <th style="width: 8%;">pH Máximo</th>
                                <th style="width: 10%;">ORP Médio (mV)</th>
                                <th style="width: 9%;">Cl. Livre Manual</th>
                                <th style="width: 11%;">Temp. Água Média (°C)</th>
                                <th style="width: 7%;">pH Conforme</th>
                                <th style="width: 14%;">Excluído (motivo)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($controlador as $leitura)
                                @php
                                    $semLeitura = $leitura->sem_leitura_valida ?? false;
                                    $motivoExclusao = $leitura->motivo_exclusao ?? null;
                                    $phMed = $leitura->ph_avg !== null ? round((float) $leitura->ph_avg, 2) : null;
                                    $phMedFora = $phMed !== null && ($phMed < $phMin || $phMed > $phMax);
                                    $phConforme = $phMed !== null && !$phMedFora;
                                    $clManual = $leitura->manual_cloro_livre ?? null;
                                @endphp
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($leitura->dia)->format('d/m/Y') }}</td>
                                    @if ($semLeitura)
                                        <td colspan="8" class="texto" style="font-style: italic;">Sem leitura válida — {{ $motivoExclusao }}</td>
                                    @else
                                        <td>{{ $leitura->leituras }}</td>
                                        <td>
                                            @if ($phMed !== null)
                                                <span @class(['fora-gama' => $phMedFora])>{{ number_format($phMed, 2, ',', '') }}</span>
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($leitura->ph_min !== null)
                                                @php $v = round((float) $leitura->ph_min, 2); @endphp
                                                <span @class(['fora-gama' => $v < $phMin || $v > $phMax])>{{ number_format($v, 2, ',', '') }}</span>
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($leitura->ph_max !== null)
                                                @php $v = round((float) $leitura->ph_max, 2); @endphp
                                                <span @class(['fora-gama' => $v < $phMin || $v > $phMax])>{{ number_format($v, 2, ',', '') }}</span>
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($leitura->orp_avg !== null)
                                                {{ number_format(round((float) $leitura->orp_avg, 0), 0, ',', '') }}
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($clManual !== null)
                                                {{ number_format($clManual, 2, ',', '') }}
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($leitura->temp_avg !== null)
                                                {{ number_format(round((float) $leitura->temp_avg, 1), 1, ',', '') }}
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($phMed !== null)
                                                @if ($phConforme) ✓ @else <span class="nao-conforme">✗</span> @endif
                                            @else — @endif
                                        </td>
                                    @endif
                                    <td class="texto">{{ $motivoExclusao ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <table class="registos controlador">
                        <thead>
                            <tr>
                                <th style="width: 10%;">Data</th>
                                <th style="width: 8%;">Hora</th>
                                <th style="width: 11%;">pH</th>
                                <th style="width: 12%;">ORP (mV)</th>
                                <th style="width: 15%;">Cl. Livre Manual</th>
                                <th style="width: 12%;">Temp. Água (°C)</th>
                                <th style="width: 9%;">pH Conforme</th>
                                <th style="width: 23%;">Excluído (motivo)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($controlador as $leitura)
                                @php
                                    $semLeitura = $leitura->sem_leitura_valida ?? false;
                                    $motivoExclusao = $leitura->motivo_exclusao ?? null;
                                    $ph = $leitura->ph !== null ? round((float) $leitura->ph, 2) : null;
                                    $phFora = $ph !== null && ($ph < $phMin || $ph > $phMax);
                                    $phConforme = $ph !== null && !$phFora;
                                    $clManual = $leitura->manual_cloro_livre ?? null;
                                @endphp
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($leitura->dia)->format('d/m/Y') }}</td>
                                    <td>{{ $leitura->hora ?? '—' }}</td>
                                    @if ($semLeitura && $ph === null)
                                        <td colspan="5" class="texto" style="font-style: italic;">Sem leitura válida</td>
                                    @else
                                        <td>
                                            @if ($ph !== null)
                                                <span @class(['fora-gama' => $phFora])>{{ number_format($ph, 2, ',', '') }}</span>
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($leitura->orp !== null)
                                                {{ number_format(round((float) $leitura->orp, 0), 0, ',', '') }}
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($clManual !== null)
                                                {{ number_format($clManual, 2, ',', '') }}
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($leitura->temp_agua !== null)
                                                {{ number_format(round((float) $leitura->temp_agua, 1), 1, ',', '') }}
                                            @else — @endif
                                        </td>
                                        <td>
                                            @if ($ph !== null)
                                                @if ($phConforme) ✓ @else <span class="nao-conforme">✗</span> @endif
                                            @else — @endif
                                        </td>
                                    @endif
                                    <td class="texto">{{ $motivoExclusao ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @php
                    $totalLeituras = $controlador->sum('leituras');
                    $isTodos = isset($controladorModo) && $controladorModo === 'todos';
                    $diasFora = $isTodos 
                        ? $controlador->filter(fn ($l) => ($l->ph ?? null) !== null && ((float) $l->ph < $phMin || (float) $l->ph > $phMax))->count()
                        : $controlador->filter(fn ($l) => ($l->ph_avg ?? null) !== null && ((float) $l->ph_avg < $phMin || (float) $l->ph_avg > $phMax))->count();
                    $diasComDados = $isTodos
                        ? $controlador->filter(fn ($l) => ($l->leituras ?? 0) > 0)->unique('dia')->count()
                        : $controlador->filter(fn ($l) => ($l->leituras ?? 0) > 0)->count();
                    $diasArtefacto = $isTodos
                        ? $controlador->filter(fn ($l) => ! empty($l->motivo_exclusao))->unique('dia')->count()
                        : $controlador->filter(fn ($l) => ! empty($l->motivo_exclusao))->count();
                @endphp
                <p class="resumo">
                    <strong>Controlador — {{ $piscina->name }}:</strong>
                    {{ $totalLeituras }} leituras automáticas em {{ $diasComDados }} {{ $diasComDados === 1 ? 'dia' : 'dias' }}
                    | {{ $isTodos ? 'Leituras com' : 'Dias com' }} pH {{ $isTodos ? '' : 'médio ' }}fora de gama: <strong>{{ $diasFora }}</strong>
                    @if ($diasArtefacto > 0)| Dias com leituras excluídas (lavagem/bomba parada): <strong>{{ $diasArtefacto }}</strong>@endif
                    | Intervalo de conformidade pH: {{ $phMin }} – {{ $phMax }}
                </p>
                <p class="resumo" style="font-size: 7px; border: none; padding: 2px 0;">
                    Nota: valores anómalos registados durante lavagem/enxaguamento do filtro ou com a bomba parada são mantidos na média para evidência da DGS, mas devidamente justificados — nesses curtos períodos a água não circula normalmente no sensor e os valores não refletem a qualidade real.
                </p>
                @endif
            @endif

            {{-- ================================================================
                 Ações Operacionais — eventos pontuais (torneira, filtro, contador,
                 bomba, tanque, análise rápida) que podem justificar valores fora
                 dos limites legais no período.
                 ================================================================ --}}
            @php
                $todasAcoes = collect();
                if ($acoesOperacionais->isNotEmpty()) {
                    foreach ($acoesOperacionais as $a) {
                        $todasAcoes->push((object)[
                            'ts' => $a->registado_em,
                            'tipo' => $a->tipoLabel(),
                            'user' => $a->utilizador?->name ?? '—',
                            'dados' => $a->dadosFormatados(),
                            'obs' => $a->observacoes
                        ]);
                    }
                }
                if (isset($seccao['filter_checks']) && $seccao['filter_checks']->isNotEmpty()) {
                    foreach ($seccao['filter_checks'] as $fc) {
                        $todasAcoes->push((object)[
                            'ts' => $fc->verificado_em,
                            'tipo' => $fc->tipo_operacao === 'enxaguamento' ? 'Enxaguamento de filtro (Painel)' : 'Lavagem de filtro (Painel)',
                            'user' => $fc->utilizador?->name ?? '—',
                            'dados' => 'Pelo esquema interativo',
                            'obs' => $fc->observacoes
                        ]);
                    }
                }
                $todasAcoes = $todasAcoes->sortBy('ts');
            @endphp

            @if ($todasAcoes->isNotEmpty() && in_array('mostrar_acoes_operacionais', $seccoesVisiveis))
                <p class="controlador-titulo">
                    Ações Operacionais
                    <span class="controlador-subtitulo">(eventos pontuais registados fora do registo diário completo)</span>
                </p>
                <table class="registos">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Hora</th>
                            <th>Ação</th>
                            <th>Responsável</th>
                            <th>Valores</th>
                            <th>Observações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($todasAcoes as $acao)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($acao->ts)->format('d/m/Y') }}</td>
                                <td>{{ \Carbon\Carbon::parse($acao->ts)->format('H:i') }}</td>
                                <td class="texto">{{ $acao->tipo }}</td>
                                <td class="texto">{{ $acao->user }}</td>
                                <td class="texto">{{ $acao->dados }}</td>
                                <td class="texto">{{ filled($acao->obs) ? \Illuminate\Support\Str::limit((string) $acao->obs, 80) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- ================================================================
                 Ocorrências e Incidentes — reportes e problemas na instalação
                 ================================================================ --}}
            @if (isset($seccao['incidentes']) && $seccao['incidentes']->isNotEmpty() && in_array('mostrar_incidentes', $seccoesVisiveis))
                <p class="controlador-titulo">
                    Ocorrências e Incidentes
                    <span class="controlador-subtitulo">(problemas reportados que podem afetar o funcionamento normal)</span>
                </p>
                <table class="registos">
                    <thead>
                        <tr>
                            <th style="width: 12%;">Data</th>
                            <th style="width: 15%;">Tipo</th>
                            <th style="width: 15%;">Reportado por</th>
                            <th style="width: 25%;">Descrição</th>
                            <th style="width: 13%;">Estado</th>
                            <th style="width: 20%;">Resolução</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($seccao['incidentes'] as $inc)
                            <tr>
                                <td>{{ $inc->ocorreu_em->format('d/m/Y H:i') }}</td>
                                <td class="texto">{{ ucfirst($inc->type ?? 'Geral') }}</td>
                                <td class="texto">{{ $inc->utilizador?->name ?? '—' }}</td>
                                <td class="texto">{{ \Illuminate\Support\Str::limit((string) $inc->descricao, 100) }}</td>
                                <td>
                                    @if ($inc->estaResolvido())
                                        Resolvido
                                    @else
                                        <span class="nao-conforme">Em aberto</span>
                                    @endif
                                </td>
                                <td class="texto">
                                    @if ($inc->estaResolvido())
                                        {{ $inc->resolvido_em?->format('d/m/Y') }} por {{ $inc->resolvidoPor?->name ?? '—' }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- ================================================================
                 Reposições e Consumos Químicos (Bidões)
                 ================================================================ --}}
            @if (isset($seccao['consumos_quimicos']) && $seccao['consumos_quimicos']->isNotEmpty() && in_array('mostrar_consumos_quimicos', $seccoesVisiveis))
                <p class="controlador-titulo">
                    Reposições e Consumos Químicos
                    <span class="controlador-subtitulo">(registos de bidões e níveis de químicos)</span>
                </p>
                <table class="registos">
                    <thead>
                        <tr>
                            <th style="width: 12%;">Data</th>
                            <th style="width: 10%;">Ação</th>
                            <th style="width: 15%;">Químico</th>
                            <th style="width: 15%;">Quantidade</th>
                            <th style="width: 15%;">Responsável</th>
                            <th style="width: 33%;">Nota</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($seccao['consumos_quimicos'] as $log)
                            <tr>
                                <td>{{ $log->registado_em->format('d/m/Y H:i') }}</td>
                                <td class="texto">{{ ucfirst($log->tipo_movimento) }}</td>
                                <td class="texto">{{ $log->container?->tipoLabel() ?? '—' }}</td>
                                <td>
                                    {{ $log->quantidade_ml > 0 ? '+' : '' }}{{ number_format((float) $log->quantidade_ml / 1000, 2) }} L
                                    <br><span style="font-size: 6px;">(Ficou: {{ number_format((float) $log->restante_apos_ml / 1000, 2) }} L)</span>
                                </td>
                                <td class="texto">{{ $log->utilizador?->name ?? ($log->origem === 'sonda' ? 'Sistema Automático' : '—') }}</td>
                                <td class="texto">{{ filled($log->nota) ? \Illuminate\Support\Str::limit((string) $log->nota, 80) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

        </div>
    @endforeach

    {{-- Área de assinaturas (última página) --}}
    @if (in_array('mostrar_assinaturas', $seccoesVisiveis))
    <table class="assinaturas">
        <tr>
            <td>
                <div class="linha">Responsável Técnico</div>
                <div class="data-assinatura">Data: ____ / ____ / ________</div>
            </td>
            <td>
                <div class="linha">Diretor de Instalação</div>
                <div class="data-assinatura">Data: ____ / ____ / ________</div>
            </td>
        </tr>
    </table>
    @endif

    @if (in_array('mostrar_nota_legal', $seccoesVisiveis))
    <p class="nota-legal">
        Registo conforme CN 14/DA (DGS 2009), NP 4542:2017 e DR 5/97.
        Documento gerado eletronicamente pela aplicação de gestão operacional MMCrespo em {{ $emitidoEm->format('d/m/Y H:i') }}.
    </p>
    @endif

</body>
</html>
