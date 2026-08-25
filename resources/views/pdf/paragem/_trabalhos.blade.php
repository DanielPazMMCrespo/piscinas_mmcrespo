@php
    $mostrarExecucao = $mostrarExecucao ?? false;
@endphp

<table class="tabela-dados">
    <thead>
        <tr>
            <th style="width: 4%; text-align: center;">#</th>
            <th style="width: {{ $mostrarExecucao ? '26%' : '44%' }};">Trabalho / Obrigação Técnica</th>
            @if(!$mostrarExecucao)
                <th style="width: 18%;">Data Prevista</th>
                <th style="width: 34%;">Observações / Âmbito</th>
            @else
                <th style="width: 10%; text-align: center;">Origem</th>
                <th style="width: 12%; text-align: center;">Estado</th>
                <th style="width: 20%;">Execução & Técnico</th>
                <th style="width: 28%;">Valores / Provas / Justificação</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse($trabalhos as $t)
            <tr class="{{ $t->origem === \App\Constants\TrabalhoParagem::ORIGEM_INFERIDA ? 'linha-inferida' : '' }}">
                <td style="text-align: center; font-weight: bold;">{{ $t->ordem }}</td>
                <td>
                    <strong>{{ $t->tipoLabel() }}</strong>
                    @if($t->obrigatorio)
                        <span class="badge badge-obrigatorio" style="margin-left: 2px;">Obrigatório</span>
                    @endif
                    @if(!$mostrarExecucao && filled($t->observacoes))
                        <div style="font-size: 7px; color: #4b5563; margin-top: 2px;">{{ $t->observacoes }}</div>
                    @endif
                </td>

                @if(!$mostrarExecucao)
                    <td>
                        {{ $t->previsto_para ? $t->previsto_para->format('d/m/Y') : 'A definir' }}
                    </td>
                    <td>
                        {{ $t->observacoes ?? '—' }}
                    </td>
                @else
                    <td style="text-align: center;">
                        <span class="badge" style="font-size: 6.5px; background: #e5e7eb; color: #374151;">
                            {{ \App\Constants\TrabalhoParagem::origemLabel($t->origem) }}
                        </span>
                    </td>
                    <td style="text-align: center;">
                        @if($t->estado === \App\Constants\TrabalhoParagem::ESTADO_EXECUTADO)
                            <span class="badge badge-executado">Executado</span>
                        @elseif($t->estado === \App\Constants\TrabalhoParagem::ESTADO_PREVISTO)
                            <span class="badge badge-previsto">Previsto</span>
                        @elseif($t->estado === \App\Constants\TrabalhoParagem::ESTADO_NAO_EXECUTADO)
                            <span class="badge badge-nao-executado">Não Executado</span>
                        @elseif($t->estado === \App\Constants\TrabalhoParagem::ESTADO_NAO_APLICAVEL)
                            <span class="badge badge-nao-aplicavel">Não Aplicável</span>
                        @else
                            <span class="badge">{{ ucfirst($t->estado) }}</span>
                        @endif
                    </td>
                    <td>
                        @if($t->executado_em)
                            <div><strong>Data:</strong> {{ $t->executado_em->format('d/m/Y H:i') }}</div>
                            <div><strong>Técnico:</strong> {{ $t->executadoPor->name ?? 'Sistema / Sonda' }}</div>
                        @elseif($t->previsto_para)
                            <div style="color: #6b7280;">Previsto: {{ $t->previsto_para->format('d/m/Y') }}</div>
                        @else
                            <div style="color: #9ca3af;">Sem data</div>
                        @endif
                    </td>
                    <td>
                        @if($t->estado === \App\Constants\TrabalhoParagem::ESTADO_EXECUTADO)
                            @if(filled($t->dadosFormatados()))
                                <div style="margin-bottom: 2px;">{{ $t->dadosFormatados() }}</div>
                            @endif
                            @if(filled($t->observacoes))
                                <div style="font-size: 7px; color: #374151; font-style: italic;">{{ $t->observacoes }}</div>
                            @endif
                            @if(is_array($t->documentos) && count($t->documentos) > 0)
                                <div style="font-size: 6.5px; color: #1e40af; font-weight: bold; margin-top: 2px;">
                                    ✓ {{ count($t->documentos) }} boletim/documento arquivado
                                </div>
                            @endif
                        @elseif(in_array($t->estado, [\App\Constants\TrabalhoParagem::ESTADO_NAO_EXECUTADO, \App\Constants\TrabalhoParagem::ESTADO_NAO_APLICAVEL], true))
                            <div style="color: #991b1b; font-weight: bold;">Motivo / Fundamentação:</div>
                            <div style="color: #4b5563;">{{ $t->motivo_nao_execucao ?? '—' }}</div>
                        @else
                            <span style="color: #9ca3af;">Pendente de execução</span>
                        @endif
                    </td>
                @endif
            </tr>
        @empty
            <tr>
                <td colspan="{{ $mostrarExecucao ? 6 : 4 }}" style="text-align: center; color: #6b7280; padding: 12px;">
                    Nenhum trabalho registado no plano.
                </td>
            </tr>
        @endforelse
    </tbody>
</table>
