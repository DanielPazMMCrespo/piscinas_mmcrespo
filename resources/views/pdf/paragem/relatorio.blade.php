<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="utf-8">
    <title>Relatório de Paragem Técnica e Manutenção — {{ $encerramento->piscina->nome_completo ?? 'Piscina' }}</title>
    @include('pdf.paragem._estilos')
</head>
<body>
    @include('pdf.paragem._cabecalho', ['tituloDocumento' => 'Relatório Técnico de Paragem e Manutenção'])

    {{-- 1. Identificação --}}
    <div class="seccao">
        <div class="seccao-titulo">1. Identificação da Paragem Técnica</div>
        <table class="meta-grid">
            <tr>
                <td class="label">Instalação / Piscina</td>
                <td>{{ $encerramento->piscina->instalacao->name ?? '—' }} — <strong>{{ $encerramento->piscina->name ?? '—' }}</strong></td>
                <td class="label">Motivo</td>
                <td>{{ $encerramento->motivo_label }}</td>
            </tr>
            <tr>
                <td class="label">Período de Paragem</td>
                <td>{{ $encerramento->descricao_periodo }} ({{ $encerramento->dias }} {{ $encerramento->dias === 1 ? 'dia' : 'dias' }})</td>
                <td class="label">Estado da Paragem</td>
                <td><strong>{{ $encerramento->esta_vigente ? 'Em curso / Vigente' : 'Concluída / Reaberta' }}</strong></td>
            </tr>
            <tr>
                <td class="label">Encerramento por</td>
                <td>{{ $encerramento->encerradaPor->name ?? '—' }}</td>
                <td class="label">Reabertura por</td>
                <td>{{ $encerramento->reabertaPor->name ?? ($encerramento->esta_vigente ? 'Em aberto' : '—') }}</td>
            </tr>
            <tr>
                <td class="label">Regime da Água</td>
                <td>{{ $encerramento->agua_em_tratamento ? 'Água mantida em tratamento químico' : 'Tanque vazio / circuito parado' }}</td>
                <td class="label">Observações</td>
                <td>{{ filled($encerramento->observacoes) ? 'Ver Anexo A.1 — Declarações do Responsável Técnico.' : 'Sem observações registadas.' }}</td>
            </tr>
        </table>
    </div>

    {{-- 2. Resumo de Execução --}}
    <div class="seccao">
        <div class="seccao-titulo">2. Resumo de Cumprimento e Execução de Trabalhos</div>
        <div class="resumo-bloco">
            <strong>Resultado da Paragem:</strong>
            {{ $resumo['total'] }} trabalhos no plano &nbsp;·&nbsp;
            <span style="color: #166534; font-weight: bold;">{{ $resumo['executados'] }} executados</span> &nbsp;·&nbsp;
            <span style="color: #854d0e;">{{ $resumo['previstos'] ?? 0 }} previstos/pendentes</span> &nbsp;·&nbsp;
            <span style="color: #991b1b;">{{ $resumo['nao_executados'] ?? 0 }} não executados</span> &nbsp;·&nbsp;
            <span style="color: #4b5563;">{{ $resumo['nao_aplicaveis'] ?? 0 }} não aplicáveis</span>
            @if(($resumo['obrigatorios_em_falta'] ?? 0) > 0)
                <div style="color: #991b1b; font-weight: bold; margin-top: 4px;">
                    ⚠ ATENÇÃO: {{ $resumo['obrigatorios_em_falta'] }} obrigação(ões) legal(is) em falta ou por justificar!
                </div>
            @else
                <div style="color: #166534; font-weight: bold; margin-top: 4px;">
                    ✓ Todas as obrigações legais regulamentares foram concluídas ou justificadas formalmente.
                </div>
            @endif
        </div>

        @include('pdf.paragem._trabalhos', ['trabalhos' => $trabalhos, 'mostrarExecucao' => true])
    </div>

    {{-- 3. Evidência do Controlador / Gráfico Sonda --}}
    @if(isset($dadosSonda) && $dadosSonda['total_leituras'] > 0)
        <div class="seccao quebra">
            <div class="seccao-titulo">3. Evidência Instrumental do Controlador Automático</div>
            <p style="font-size: 7.5px; color: #374151; margin-bottom: 6px;">
                <strong>Cobertura:</strong> {{ $dadosSonda['total_leituras'] }} leituras registadas entre {{ $dadosSonda['de']->format('d/m/Y H:i') }} e {{ $dadosSonda['ate']->format('d/m/Y H:i') }} (cadência 15 min, taxa de cobertura de {{ $dadosSonda['cobertura_percent'] }}% do período).
            </p>

            @if(filled($graficoSvg))
                <div class="grafico-wrapper">
                    {!! $graficoSvg !!}
                    <div class="grafico-legenda">
                        <strong>Evolução Instrumental Média Horária:</strong> Superior: Potencial Redox / ORP (mV) com banda regulamentar [{{ $encerramento->piscina->orp_min ?? 650 }}–{{ $encerramento->piscina->orp_max ?? 800 }} mV]. Inferior: Temperatura da Água (°C) com banda operacional [{{ $encerramento->piscina->temp_min ?? 26 }}–{{ $encerramento->piscina->temp_max ?? 28 }} °C].
                    </div>
                </div>
            @endif

            @if(!empty($eventosSonda))
                <div style="margin-top: 8px;">
                    <strong>Eventos e Padrões Físico-Químicos Detetados:</strong>
                    <table class="tabela-dados" style="margin-top: 4px;">
                        <thead>
                            <tr>
                                <th style="width: 20%;">Evento Detetado</th>
                                <th style="width: 20%;">Intervalo Coberto</th>
                                <th style="width: 8%; text-align: center;">Ocorr.</th>
                                <th style="width: 10%; text-align: center;">Confiança</th>
                                <th style="width: 42%;">Critério Físico / Leituras</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($eventosSonda as $ev)
                                <tr>
                                    <td><strong>{{ $ev['tipo_label'] ?? $ev['tipo'] }}</strong></td>
                                    <td>
                                        @if(($ev['ocorrencias'] ?? 1) > 1)
                                            {{ $ev['momento']->format('d/m/Y') }}
                                            @if(isset($ev['fim']) && $ev['fim'])
                                                a {{ $ev['fim']->format('d/m/Y') }}
                                            @endif
                                        @else
                                            {{ $ev['momento']->format('d/m/Y H:i') }}
                                            @if(isset($ev['fim']) && $ev['fim'])
                                                a {{ $ev['fim']->format('d/m/Y H:i') }}
                                            @endif
                                        @endif
                                    </td>
                                    <td style="text-align: center;">{{ $ev['ocorrencias'] ?? 1 }}</td>
                                    <td style="text-align: center;">
                                        <span class="badge" style="background: #e0f2fe; color: #0369a1;">{{ ucfirst($ev['confianca']) }}</span>
                                    </td>
                                    <td>{{ $ev['criterio'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- 4. Provas Documentais e Anexos (Boletins e Fotos) --}}
    @if((isset($documentosIndex) && count($documentosIndex) > 0) || (isset($fotosEmbed) && count($fotosEmbed) > 0) || (isset($fotosNaoEmbutidas) && count($fotosNaoEmbutidas) > 0) || (isset($videosIndex) && count($videosIndex) > 0))
        <div class="seccao {{ !isset($dadosSonda) || $dadosSonda['total_leituras'] === 0 ? 'quebra' : '' }}">
            <div class="seccao-titulo">4. Arquivo Documental e Evidências Fotográficas</div>

            @if(isset($documentosIndex) && count($documentosIndex) > 0)
                <div style="margin-bottom: 10px;">
                    <div style="font-weight: bold; font-size: 8px; margin-bottom: 4px;">Boletins Analíticos e Certificados de Laboratório Acreditado:</div>
                    <table class="tabela-dados">
                        <thead>
                            <tr>
                                <th style="width: 5%; text-align: center;">#</th>
                                <th style="width: 22%;">Trabalho Associado</th>
                                <th style="width: 25%;">Laboratório / Entidade</th>
                                <th style="width: 15%;">Data Colheita / Boletim</th>
                                <th style="width: 15%;">Resultado</th>
                                <th style="width: 18%;">Ficheiro & Hash SHA-256</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($documentosIndex as $doc)
                                <tr>
                                    <td style="text-align: center; font-weight: bold;">{{ $loop->iteration }}</td>
                                    <td>{{ $doc['tarefa_label'] }}</td>
                                    <td><strong>{{ $doc['laboratorio'] ?? 'Laboratório Externo' }}</strong><br><span style="font-size: 6.5px; color: #6b7280;">Nº: {{ $doc['numero_boletim'] ?? '—' }}</span></td>
                                    <td>{{ $doc['data_boletim'] ?? '—' }}</td>
                                    <td><strong>{{ $doc['resultado'] ?? 'Conforme' }}</strong></td>
                                    <td style="font-family: monospace; font-size: 6px;">
                                        {{ $doc['nome_ficheiro'] }}<br>
                                        <span style="color: #6b7280;">SHA: {{ substr($doc['sha256'] ?? '—', 0, 16) }}...</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(isset($fotosEmbed) && count($fotosEmbed) > 0)
                <div style="margin-top: 8px;">
                    <div style="font-weight: bold; font-size: 8px; margin-bottom: 4px;">Registo Fotográfico da Intervenção:</div>
                    <div class="fotos-grid">
                        @foreach($fotosEmbed as $foto)
                            <div class="foto-box">
                                <img src="{{ $foto['base64'] }}" alt="Foto">
                                <div class="foto-legenda">
                                    <strong>{{ $foto['tarefa_label'] }}</strong><br>
                                    {{ $foto['data'] ?? '' }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- O dompdf nao reproduz video: o clipe fica referenciado com o
                 SHA-256 do ficheiro arquivado, para se poder provar que o
                 video visto no sistema e o mesmo que este relatorio cita. --}}
            @if(isset($videosIndex) && count($videosIndex) > 0)
                <div style="margin-top: 8px;">
                    <div style="font-weight: bold; font-size: 8px; margin-bottom: 4px;">Registo em vídeo da intervenção (não reproduzível em papel — abrir pela ligação):</div>
                    <table class="tabela-dados">
                        <thead>
                            <tr>
                                <th style="width: 5%; text-align: center;">#</th>
                                <th style="width: 26%;">Trabalho Associado</th>
                                <th style="width: 13%;">Data / Hora</th>
                                <th style="width: 10%;">Dimensão</th>
                                <th style="width: 46%;">Ligação de Visualização</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($videosIndex as $video)
                                <tr>
                                    <td style="text-align: center; font-weight: bold;">{{ $loop->iteration }}</td>
                                    <td>{{ $video['tarefa_label'] }}</td>
                                    <td>{{ $video['data'] ?? '—' }}</td>
                                    <td>{{ $video['tamanho_mb'] }}</td>
                                    <td style="font-size: 6.5px;">
                                        @if(filled($video['url'] ?? null))
                                            <a href="{{ $video['url'] }}" style="color: #1e40af; font-family: monospace; word-break: break-all;">{{ $video['url'] }}</a>
                                        @else
                                            <span style="font-family: monospace;">{{ $video['nome_ficheiro'] }}</span>
                                            <span style="color: #991b1b;">(ligação indisponível — ficheiro arquivado no sistema)</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p style="font-size: 6.5px; color: #4b5563; margin-top: 3px;">
                        A ligação abre o ficheiro de vídeo original, tal como arquivado no sistema no momento da execução do trabalho.
                    </p>
                </div>
            @endif

            @if(isset($fotosNaoEmbutidas) && count($fotosNaoEmbutidas) > 0)
                <div style="margin-top: 8px;">
                    <div style="font-weight: bold; font-size: 8px; margin-bottom: 4px;">Anexos fotográficos não impressos (arquivados no sistema):</div>
                    <table class="registos">
                        <thead>
                            <tr>
                                <th style="width: 40%;">Trabalho</th>
                                <th style="width: 32%;">Ficheiro</th>
                                <th style="width: 28%;">Motivo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($fotosNaoEmbutidas as $foto)
                                <tr>
                                    <td>{{ $foto['tarefa_label'] }}</td>
                                    <td>{{ $foto['nome_ficheiro'] }}</td>
                                    <td>{{ $foto['motivo'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- 5. Anexo A: Ações Operacionais no Período --}}
    @if(isset($acoesOperacionais) && $acoesOperacionais->count() > 0)
        <div class="seccao quebra">
            <div class="seccao-titulo">Anexo A — Ações Operacionais Registadas no Período da Paragem</div>
            <p style="font-size: 7px; color: #4b5563; margin-bottom: 6px;">
                Registo de intervenções manuais executadas pela equipa técnica e colaboradores durante o intervalo de encerramento da piscina:
            </p>
            <table class="tabela-dados">
                <thead>
                    <tr>
                        <th style="width: 14%;">Data/Hora</th>
                        <th style="width: 18%;">Operador</th>
                        <th style="width: 22%;">Ação Operacional</th>
                        <th style="width: 46%;">Parâmetros / Detalhes / Observações</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($acoesOperacionais as $acao)
                        <tr>
                            <td>{{ $acao->registado_em ? $acao->registado_em->format('d/m/Y H:i') : $acao->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $acao->utilizador->name ?? '—' }}</td>
                            <td><strong>{{ $acao->tipoLabel() }}</strong></td>
                            <td>
                                {{ $acao->dadosFormatados() }}
                                @if(filled($acao->observacoes))
                                    <div style="color: #4b5563; font-style: italic; font-size: 7px;">{{ $acao->observacoes }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p style="font-size: 7px; color: #4b5563; margin-top: 4px;">
                As análises constantes deste anexo foram colhidas com a piscina encerrada ao público, em regime de manutenção
                e sem carga de banhistas. Os valores de conformidade para utilização são os aferidos na verificação de
                parâmetros pré-reabertura, constante do ponto 2 deste relatório.
            </p>
        </div>
    @endif

    {{-- 5.1 Declarações do responsável técnico (factos não instrumentados) --}}
    @if(filled($encerramento->observacoes))
        <div class="seccao" style="margin-top: 10px;">
            <div class="seccao-titulo">Anexo A.1 — Declarações do Responsável Técnico</div>
            <p style="font-size: 7px; color: #4b5563; margin-bottom: 4px;">
                Operações executadas no período cujo registo não foi lançado na aplicação no momento da execução.
                Constam aqui como declaração expressa do responsável técnico, sem suporte instrumental automático.
            </p>
            <div style="font-size: 8px; color: #111827; border-left: 2px solid #0284c7; padding-left: 6px;">
                {!! nl2br(e($encerramento->observacoes)) !!}
            </div>
        </div>
    @endif

    {{-- 6. O Que Não É Possível Provar Automaticamente --}}
    <div class="aviso-limitacoes">
        <h4>Limitações Técnicas e Declaração de Rastreabilidade Físico-Química</h4>
        <ul>
            <li><strong>Limpeza mecânica e desincrustação:</strong> As operações de escovagem física de paredes, caleiras, tanques de compensação e filtros de areia dependem exclusivamente da validação presencial do técnico executor, não sendo mensuráveis por sonda química.</li>
            <li><strong>Controlo de Legionella:</strong> A comprovação de conformidade para <em>Legionella pneumophila</em> é atestada pelo boletim analítico emitido por laboratório acreditado anexo a este relatório.</li>
            <li><strong>Níveis de Cloro em mg/L:</strong> O controlador automático mede o Potencial Redox / ORP (mV). A validação do ORP contra o método fotométrico de referência (DPD1) consta do Anexo B.</li>
        </ul>
    </div>

    {{-- Fundamentacao do ORP como fonte de cloro. Sai sempre: quando nao ha
         pares suficientes, o que se imprime e a ausencia de validacao, nao um
         numero sem suporte. --}}
    @if(isset($correlacaoOrp) && is_array($correlacaoOrp))
        <div class="seccao quebra">
            <div class="seccao-titulo">Anexo B — Validação do Potencial Redox (ORP) como Indicador de Cloro Livre</div>

            <p style="font-size: 7.5px; text-align: justify; margin: 0 0 6px 0;">
                O Potencial Redox mede o <strong>poder oxidante efetivo</strong> da água, ou seja, a capacidade real de
                inativação microbiológica — que é o parâmetro sanitariamente relevante. O cloro livre em mg/L é uma
                medida de massa e não traduz, por si só, essa capacidade: o mesmo teor de cloro tem poder desinfetante
                diferente consoante o pH, por variação do equilíbrio entre ácido hipocloroso e ião hipoclorito.
                Por esse motivo o ORP é internacionalmente usado como indicador de desinfeção adequada, sendo o valor de
                referência mais citado na literatura de piscinas <strong>≈ 720 mV a pH 7,2–7,8</strong>. A banda de
                referência configurada para esta piscina é
                <strong>{{ number_format((float) ($encerramento->piscina->orp_min ?? 650), 0, ',', '') }}–{{ number_format((float) ($encerramento->piscina->orp_max ?? 800), 0, ',', '') }} mV</strong>.
            </p>

            <p style="font-size: 7.5px; text-align: justify; margin: 0 0 6px 0;">
                Para que a leitura contínua da sonda possa ser lida como cloro livre, foi construída uma
                <strong>correlação local</strong> entre o ORP registado pela sonda e as medições manuais a DPD1
                efetuadas nesta mesma piscina, emparelhadas no tempo (tolerância de 30 minutos). O que se segue é a
                validação do método indirecto contra o método de referência, com os dados da própria instalação.
            </p>

            @if(($correlacaoOrp['n'] ?? 0) > 0)
                <table class="tabela-dados" style="margin-bottom: 6px;">
                    <thead>
                        <tr>
                            <th style="width: 22%;">Data / Hora da colheita</th>
                            <th style="width: 20%;">Origem da medição</th>
                            <th style="width: 20%; text-align: center;">ORP da sonda (mV)</th>
                            <th style="width: 20%; text-align: center;">Cloro livre DPD1 (mg/L)</th>
                            <th style="width: 18%; text-align: center;">pH</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($correlacaoOrp['pares'] as $par)
                            <tr>
                                <td>{{ $par['momento']->format('d/m/Y H:i') }}</td>
                                <td>{{ $par['origem'] }}</td>
                                <td style="text-align: center;">{{ number_format($par['orp'], 0, ',', '') }}</td>
                                <td style="text-align: center;"><strong>{{ number_format($par['cloro_livre'], 2, ',', '') }}</strong></td>
                                <td style="text-align: center;">{{ $par['ph'] === null ? '—' : number_format($par['ph'], 2, ',', '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if($correlacaoOrp['utilizavel'] ?? false)
                <div style="font-size: 7.5px; border-left: 3px solid #059669; background: #f0fdf4; padding: 5px 7px;">
                    <strong>Correlação validada.</strong>
                    Pares emparelhados: <strong>n = {{ $correlacaoOrp['n'] }}</strong>.
                    Coeficiente de correlação de Pearson: <strong>r = {{ number_format((float) $correlacaoOrp['r'], 3, ',', '') }}</strong>.
                    Relação obtida por mínimos quadrados:
                    <strong>Cloro livre (mg/L) = {{ number_format((float) $correlacaoOrp['declive'], 5, ',', '') }} &times; ORP (mV) {{ ((float) $correlacaoOrp['ordenada']) < 0 ? '−' : '+' }} {{ number_format(abs((float) $correlacaoOrp['ordenada']), 3, ',', '') }}</strong>.
                    <br>
                    <strong>Domínio de validade:</strong> ORP entre
                    {{ number_format((float) $correlacaoOrp['orp_min_par'], 0, ',', '') }} e
                    {{ number_format((float) $correlacaoOrp['orp_max_par'], 0, ',', '') }} mV
                    @if($correlacaoOrp['ph_min'] !== null)
                        , com pH entre {{ number_format((float) $correlacaoOrp['ph_min'], 2, ',', '') }} e {{ number_format((float) $correlacaoOrp['ph_max'], 2, ',', '') }}
                    @endif
                    . Dentro deste domínio, a leitura contínua da sonda é aceite como estimativa rastreável de cloro
                    livre, por estar ancorada às medições DPD1 acima. Fora dele — designadamente em regime de
                    hipercloração, onde a resposta do ORP satura — a leitura vale como prova de
                    <strong>poder oxidante sustentado e do respetivo tempo de contacto</strong>, não como quantificação
                    em mg/L, que nesse caso é dada pela análise manual.
                </div>
            @else
                <div style="font-size: 7.5px; border-left: 3px solid #b45309; background: #fffbeb; padding: 5px 7px;">
                    <strong>Correlação não validada neste período.</strong>
                    Pares ORP/DPD1 emparelhados: <strong>n = {{ $correlacaoOrp['n'] ?? 0 }}</strong>@if(($correlacaoOrp['r'] ?? null) !== null), r = {{ number_format((float) $correlacaoOrp['r'], 3, ',', '') }}@endif@if($correlacaoOrp['ph_min'] !== null), com pH entre {{ number_format((float) $correlacaoOrp['ph_min'], 2, ',', '') }} e {{ number_format((float) $correlacaoOrp['ph_max'], 2, ',', '') }}@endif.
                    @if(filled($correlacaoOrp['motivo_nao_validada'] ?? null))
                        {{ $correlacaoOrp['motivo_nao_validada'] }}
                    @endif
                    <br>
                    A leitura da sonda é por isso apresentada neste relatório apenas como indicador de
                    <strong>poder oxidante e do tempo em que foi mantido</strong> — que é o parâmetro sanitariamente
                    relevante e para o qual o ORP é o método próprio. Os valores de cloro livre em mg/L aqui constantes
                    provêm exclusivamente de medição manual a DPD1.
                </div>
            @endif
        </div>
    @endif

    {{-- 7. Assinaturas --}}
    <div class="seccao" style="margin-top: 15px;">
        <div class="seccao-titulo">5. Termo de Encerramento e Assinaturas Técnicas</div>
        @include('pdf.paragem._assinaturas')
    </div>

    <div class="nota-legal">
        <strong>Certificação Regulamentar:</strong> Relatório emitido pelo sistema de gestão técnica MMCrespo, em conformidade com a Lei n.º 52/2018 (Prevenção de Legionella), Despacho n.º 1547/2022, Circular Normativa n.º 14/DA da Direção-Geral da Saúde e Decreto Regulamentar n.º 5/97.
    </div>
</body>
</html>
