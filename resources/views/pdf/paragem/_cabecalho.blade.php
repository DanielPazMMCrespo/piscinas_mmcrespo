<div class="cabecalho-fixo">
    <div class="titulo">{{ $tituloDocumento ?? 'Documento Técnico de Paragem' }}</div>
    <div class="subtitulo">
        <strong>Instalação:</strong> {{ $encerramento->piscina->instalacao->name ?? '—' }} &nbsp;|&nbsp;
        <strong>Piscina:</strong> {{ $encerramento->piscina->name ?? '—' }} &nbsp;|&nbsp;
        <strong>Período:</strong> {{ $encerramento->descricao_periodo }}
    </div>
    <div class="marca">
        MMCrespo — Gestão de Piscinas
        <div class="emissao">Emitido a {{ $emitidoEm->format('d/m/Y H:i') }}{{ filled($emitidoPor) ? ' por ' . $emitidoPor : '' }}</div>
    </div>
</div>
