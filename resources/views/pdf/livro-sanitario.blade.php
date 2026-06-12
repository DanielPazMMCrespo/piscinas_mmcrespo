{{--
    Livro de Registo Sanitário — CN 14/DA (DGS 2009) — MMCrespo
    Template para dompdf (A4 landscape). Limitações do dompdf respeitadas:
    CSS embebido em <style>, sem flex/grid, fonte DejaVu Sans (acentos PT),
    repetição de cabeçalho de tabela via <thead>, preto e branco.

    Variáveis esperadas:
      $instalacao (App\Models\Installation)
      $seccoes    (array de ['piscina' => Pool, 'registos' => Collection<DailyRecord>])
      $inicio, $fim, $emitidoEm (Carbon)  $emitidoPor (?string)
--}}
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

            // Conformidade global por registo: pH, cloro livre, cloro combinado
            // (limites legais do model) e temperatura (limites próprios da piscina).
            $conformidade = $registos->mapWithKeys(fn ($registo) => [
                $registo->id => $registo->phConforme()
                    && $registo->cloroLivreConforme()
                    && $registo->cloroCombinadoConforme()
                    && $registo->temperaturaConforme(),
            ]);

            $totalRegistos = $registos->count();
            $naoConformes = $conformidade->filter(fn ($ok) => ! $ok)->count();
            $percentagem = $totalRegistos > 0
                ? round((($totalRegistos - $naoConformes) / $totalRegistos) * 100, 1)
                : null;
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
                            <th style="width: 6%;">Data</th>
                            <th style="width: 6.5%;">Hora</th>
                            <th style="width: 9%;">Técnico</th>
                            <th style="width: 4.5%;">pH</th>
                            <th style="width: 6%;">Cl. Livre (mg/L)</th>
                            <th style="width: 6%;">Cl. Total (mg/L)</th>
                            <th style="width: 6.5%;">Cl. Combinado (mg/L)</th>
                            <th style="width: 5.5%;">Temp. (°C)</th>
                            <th style="width: 5.5%;">Transp.</th>
                            <th style="width: 7%;">Contador (m³)</th>
                            <th style="width: 7%;">Bomba / Tanque</th>
                            <th style="width: 11%;">Ação corretiva</th>
                            <th style="width: 14%;">Observações</th>
                            <th style="width: 5.5%;">Conforme</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($registos as $registo)
                            @php
                                $conforme = $conformidade[$registo->id];
                                // Negrito apenas quando o valor existe E está fora de gama.
                                $phFora = $registo->ph !== null && ! $registo->phConforme();
                                $clFora = $registo->cloro_livre !== null && ! $registo->cloroLivreConforme();
                                $combFora = $registo->cloro_total !== null
                                    && $registo->cloro_livre !== null
                                    && ! $registo->cloroCombinadoConforme();
                                $tempFora = $registo->temperatura !== null && ! $registo->temperaturaConforme();
                                // Campo em implementação noutro fluxo — acesso defensivo.
                                $acaoCorretiva = $registo->acao_corretiva ?? null;
                            @endphp
                            <tr>
                                <td>{{ $registo->registado_em?->format('d/m/Y') ?? '—' }}</td>
                                <td>
                                    {{ $registo->registado_em?->format('H:i') ?? '—' }}
                                    @if ($registo->e_correcao)
                                        <br><span style="font-size: 6px;">(correção)</span>
                                    @endif
                                </td>
                                <td class="texto">{{ $registo->utilizador?->name ?? '—' }}</td>
                                <td>
                                    @if ($registo->ph !== null)
                                        <span @class(['fora-gama' => $phFora])>{{ $registo->ph }}</span>
                                    @else — @endif
                                </td>
                                <td>
                                    @if ($registo->cloro_livre !== null)
                                        <span @class(['fora-gama' => $clFora])>{{ $registo->cloro_livre }}</span>
                                    @else — @endif
                                </td>
                                <td>{{ $registo->cloro_total ?? '—' }}</td>
                                <td>
                                    @if ($registo->cloro_total !== null && $registo->cloro_livre !== null)
                                        <span @class(['fora-gama' => $combFora])>{{ number_format($registo->cloro_combinado, 2) }}</span>
                                    @else — @endif
                                </td>
                                <td>
                                    @if ($registo->temperatura !== null)
                                        <span @class(['fora-gama' => $tempFora])>{{ $registo->temperatura }}</span>
                                    @else — @endif
                                </td>
                                <td>{{ $registo->transparencia ?? '—' }}</td>
                                <td>{{ $registo->contador_valor !== null ? number_format((float) $registo->contador_valor, 2, ',', ' ') : '—' }}</td>
                                <td>
                                    {{ $registo->bomba_ferrada === null ? '—' : ($registo->bomba_ferrada ? '✓' : '✗') }}
                                    /
                                    {{ $registo->tanque_ok === null ? '—' : ($registo->tanque_ok ? '✓' : '✗') }}
                                </td>
                                <td class="texto">{{ $acaoCorretiva !== null && $acaoCorretiva !== '' ? \Illuminate\Support\Str::limit((string) $acaoCorretiva, 70) : '—' }}</td>
                                <td class="texto">{{ filled($registo->observacoes) ? \Illuminate\Support\Str::limit((string) $registo->observacoes, 80) : '—' }}</td>
                                <td>
                                    @if ($conforme)
                                        ✓
                                    @else
                                        <span class="nao-conforme">✗</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p class="resumo">
                    <strong>Resumo — {{ $piscina->name }}:</strong>
                    {{ $totalRegistos }} {{ $totalRegistos === 1 ? 'registo' : 'registos' }} no período
                    | Não conformidades: <strong>{{ $naoConformes }}</strong>
                    | Conformidade: <strong>{{ number_format((float) $percentagem, 1, ',', '') }}%</strong>
                </p>
            @endif
        </div>
    @endforeach

    {{-- Área de assinaturas (última página) --}}
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

    <p class="nota-legal">
        Registo conforme CN 14/DA (DGS 2009), NP 4542:2017 e DR 5/97.
        Documento gerado eletronicamente pela aplicação de gestão operacional MMCrespo em {{ $emitidoEm->format('d/m/Y H:i') }}.
    </p>

</body>
</html>
