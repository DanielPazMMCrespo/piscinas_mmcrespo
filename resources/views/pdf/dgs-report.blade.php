<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Livro de Registo Sanitário - DGS</title>
    <style>
        @page { margin: 15mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #000; line-height: 1.2; }
        .page-break { page-break-after: always; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 16px; margin: 0 0 5px; text-transform: uppercase; }
        .header h2 { font-size: 12px; margin: 0 0 10px; font-weight: normal; }
        .termo { border: 1px solid #000; padding: 20px; margin-bottom: 20px; text-align: justify; }
        .termo h3 { text-align: center; font-size: 14px; text-transform: uppercase; text-decoration: underline; margin-top: 0; }
        .info-table { width: 100%; margin-bottom: 20px; border-collapse: collapse; }
        .info-table th, .info-table td { text-align: left; padding: 4px; font-size: 11px; }
        .info-table th { width: 150px; font-weight: bold; }
        
        .data-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .data-table th, .data-table td { border: 1px solid #000; padding: 4px; text-align: center; font-size: 9px; }
        .data-table th { background-color: #f2f2f2; font-weight: bold; }
        
        .signature-line { width: 250px; border-top: 1px solid #000; margin: 40px auto 10px auto; }
        .signature-text { text-align: center; font-size: 10px; }
    </style>
</head>
<body>

    <!-- Capa / Termo de Abertura -->
    <div class="header">
        <h1>Livro de Registo Sanitário de Piscina</h1>
        <h2>Circular Normativa nº 14/DA - Direção-Geral da Saúde</h2>
    </div>

    <div class="termo">
        <h3>Termo de Abertura</h3>
        <p>
            Este livro, constituído por folhas numeradas e rubricadas, destina-se a servir de Registo Diário do controlo analítico 
            e operacional da piscina denominada <strong>{{ $pool->name }}</strong>, inserida na instalação 
            <strong>{{ $pool->instalacao->name ?? '—' }}</strong>, referente ao mês de <strong>{{ $month }}</strong>.
        </p>
        <p>
            O presente livro atende aos requisitos estabelecidos pela DGS para o controlo da qualidade da água, garantindo a
            saúde e segurança dos banhistas.
        </p>
        
        <table class="info-table">
            <tr>
                <th>Instalação:</th>
                <td>{{ $pool->instalacao->name ?? '—' }}</td>
            </tr>
            <tr>
                <th>Piscina:</th>
                <td>{{ $pool->name }}</td>
            </tr>
            <tr>
                <th>Volume (m³):</th>
                <td>{{ $pool->volume ?? '—' }}</td>
            </tr>
            <tr>
                <th>Mês/Ano:</th>
                <td>{{ $month }}</td>
            </tr>
        </table>
        
        <div class="signature-line"></div>
        <div class="signature-text">Assinatura do Responsável Técnico</div>
    </div>

    <div class="page-break"></div>

    <!-- Tabela de Registos Diários -->
    <div class="header">
        <h1>Registo Diário Analítico e Operacional</h1>
        <h2>{{ $pool->name }} - {{ $month }}</h2>
    </div>

    <table class="data-table">
        <thead>
            <tr>
                <th rowspan="2">Data</th>
                <th rowspan="2">Hora</th>
                <th colspan="4">Parâmetros Físico-Químicos</th>
                <th rowspan="2">Transparência</th>
                <th rowspan="2">Lavagem Filtros</th>
                <th rowspan="2">Leitura Contador</th>
                <th rowspan="2">Banhistas</th>
                <th rowspan="2">Técnico</th>
                <th rowspan="2">Observações</th>
            </tr>
            <tr>
                <th>pH</th>
                <th>Cloro Livre<br>(mg/L)</th>
                <th>Cloro Total<br>(mg/L)</th>
                <th>Temp. ºC</th>
            </tr>
        </thead>
        <tbody>
            @php
                $daysInMonth = $startOfMonth->daysInMonth;
            @endphp

            @for ($d = 1; $d <= $daysInMonth; $d++)
                @php
                    $currentDateStr = $startOfMonth->copy()->addDays($d - 1)->format('Y-m-d');
                    $dayRecords = $groupedRecords->get($currentDateStr, []);
                @endphp

                @if(count($dayRecords) == 0)
                    <tr>
                        <td>{{ $d }}</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                        <td>—</td>
                    </tr>
                @else
                    @foreach($dayRecords as $idx => $record)
                        <tr>
                            @if($idx == 0)
                                <td rowspan="{{ count($dayRecords) }}">{{ $d }}</td>
                            @endif
                            <td>{{ $record->registado_em->format('H:i') }}</td>
                            <td>{{ $record->ph_efetivo ?? '—' }}</td>
                            <td>{{ $record->cloro_livre_efetivo ?? '—' }}</td>
                            <td>{{ $record->cloro_total_efetivo ?? '—' }}</td>
                            <td>{{ $record->temperatura_efetivo ?? '—' }}</td>
                            <td>{{ $record->transparencia !== null ? $record->transparencia . ' FNU' : '—' }}</td>
                            <td>{{ $record->filtro_faz_retrolavagem ? 'Sim' : 'Não' }}</td>
                            <td>{{ $record->contador_valor ?? '—' }}</td>
                            <td>{{ $record->banhistas ?? '0' }}</td>
                            <td>{{ $record->user->name ?? '—' }}</td>
                            <td style="text-align: left; font-size: 8px;">{{ \Illuminate\Support\Str::limit($record->observacoes, 50) }}</td>
                        </tr>
                    @endforeach
                @endif
            @endfor
        </tbody>
    </table>

    <div class="page-break"></div>

    <!-- Termo de Encerramento -->
    <div class="termo" style="margin-top: 40px;">
        <h3>Termo de Encerramento</h3>
        <p>
            O presente livro referente ao mês de <strong>{{ $month }}</strong> para a piscina <strong>{{ $pool->name }}</strong> 
            foi devidamente preenchido e verificado.
        </p>
        <p>
            Todas as anomalias, quando existentes, foram registadas no campo de observações ou em ficha de anomalia anexa, 
            tendo sido tomadas as devidas ações corretivas.
        </p>

        <div class="signature-line"></div>
        <div class="signature-text">Assinatura do Responsável Técnico</div>
    </div>

</body>
</html>
