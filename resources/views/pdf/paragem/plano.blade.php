<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>Plano de Trabalhos de Paragem Técnica — {{ $encerramento->piscina->nome_completo ?? 'Piscina' }}</title>
    @include('pdf.paragem._estilos')
</head>
<body>
    @include('pdf.paragem._cabecalho', ['tituloDocumento' => 'Plano de Trabalhos de Paragem Técnica'])

    <div class="seccao">
        <div class="seccao-titulo">1. Identificação da Paragem Técnica</div>
        <table class="meta-grid">
            <tr>
                <td class="label">Instalação / Piscina</td>
                <td>{{ $encerramento->piscina->instalacao->name ?? '—' }} — <strong>{{ $encerramento->piscina->name ?? '—' }}</strong></td>
                <td class="label">Motivo do Encerramento</td>
                <td>{{ $encerramento->motivo_label }}</td>
            </tr>
            <tr>
                <td class="label">Período Programado</td>
                <td>{{ $encerramento->descricao_periodo }} ({{ $encerramento->dias }} {{ $encerramento->dias === 1 ? 'dia' : 'dias' }})</td>
                <td class="label">Regime da Água</td>
                <td>{{ $encerramento->agua_em_tratamento ? 'Água mantida em tratamento químico' : 'Tanque parado / sem tratamento' }}</td>
            </tr>
            <tr>
                <td class="label">Registado por</td>
                <td>{{ $encerramento->encerradaPor->name ?? '—' }}</td>
                <td class="label">Observações da Paragem</td>
                <td>{{ $encerramento->observacoes ?? 'Sem observações registadas.' }}</td>
            </tr>
        </table>
    </div>

    <div class="seccao">
        <div class="seccao-titulo">2. Programa de Trabalhos e Obrigações Técnicas</div>
        <div class="resumo-bloco">
            <strong>Resumo do Plano:</strong> {{ $resumo['total'] }} trabalhos planeados &nbsp;|&nbsp;
            <strong>{{ $resumo['obrigatorios_total'] ?? 0 }} obrigações legais</strong> regulamentares (Lei n.º 52/2018, Despacho 1547/2022, CN 14/DA).
        </div>

        @include('pdf.paragem._trabalhos', ['trabalhos' => $trabalhos, 'mostrarExecucao' => false])
    </div>

    <div class="seccao" style="margin-top: 20px;">
        <div class="seccao-titulo">3. Validação e Aprovação do Plano</div>
        <p style="font-size: 7.5px; color: #4b5563; margin-bottom: 10px;">
            O presente documento constitui o plano prévio de intervenção e manutenção para a paragem técnica identificada, estabelecendo a sequência de trabalhos e os requisitos legais de desinfeção e controlo microbiológico.
        </p>

        @include('pdf.paragem._assinaturas')
    </div>

    <div class="nota-legal">
        <strong>Nota de Conformidade:</strong> Plano de paragem elaborado nos termos da Lei n.º 52/2018 (Prevenção e Controlo de Legionella), Despacho n.º 1547/2022, Circular Normativa n.º 14/DA da DGS e Decreto Regulamentar n.º 5/97. MMCrespo Software de Gestão.
    </div>
</body>
</html>
