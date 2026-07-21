@php
    $esquema = $e;
    $piscina = $esquema['piscina'];
    $pid = $piscina->id;
    $torneira = $esquema['torneira'];
    $bomba = $esquema['bomba'];
    $filtro = $esquema['filtro'];
    $tanque = $esquema['tanque'];
    $agua = $esquema['agua'];
    $registo = $esquema['registo'];
    $bidoes = $esquema['bidoes'];

    $torneiraAberta = $torneira['estado'] === 'aberta';
    $entradaComAgua = $torneiraAberta || $torneira['estado'] === 'com_agua';
    $bombaATrabalhar = $bomba['estado'] === 'a_trabalhar';
@endphp

<div
    id="circuito-{{ $pid }}"
    wire:key="esquema-{{ $pid }}"
    x-data="mmcEsquema"
    class="mmc-esq mmc-esq-detalhe-piscina"
>
    <div class="mmc-esq-detalhe-piscina__cabec">
        <h3 class="mmc-esq-detalhe-piscina__nome">{{ $piscina->name }}</h3>
        <span class="mmc-esq-detalhe-piscina__vol">{{ $piscina->volume !== null ? number_format((float) $piscina->volume, 0, ',', ' ') . ' m³' : '' }}</span>
    </div>

    @if ($torneiraAberta)
        <div class="mmc-esq__alerta" role="alert">
            <x-filament::icon icon="heroicon-s-exclamation-triangle" class="mmc-esq__alerta-icone" />
            <span>
                Torneira de água aberta desde {{ $torneira['desde'] }}
                ({{ $torneira['desde_humano'] }})@if ($torneira['aberta_por']) — registado por {{ $torneira['aberta_por'] }}@endif
            </span>
            @if (\App\Filament\Resources\OperationalActionResource::canCreate())
                <a href="{{ $esquema['url_acoes_rapidas']['torneira'] }}" class="mmc-esq__alerta-cta">Fechar torneira</a>
            @else
                <a href="{{ $esquema['url_registar'] }}" class="mmc-esq__alerta-cta">Registar fecho</a>
            @endif
        </div>
    @endif

    <div class="mmc-esq-detalhe-piscina__corpo">
        <div class="mmc-esq__svg-wrap">
        <svg
            viewBox="0 0 800 440"
            class="mmc-esq__svg"
            role="img"
            aria-label="Esquema do circuito de água de {{ $piscina->name }}"
        >
            <defs>
                <marker id="mmc-esq-seta-{{ $pid }}" viewBox="0 0 10 10" refX="8" refY="5"
                        markerWidth="3.2" markerHeight="3.2" orient="auto-start-reverse">
                    <path d="M 0 0 L 10 5 L 0 10 z" class="mmc-esq__seta" />
                </marker>
            </defs>

            {{-- Entrada: torneira → piscina --}}
            <path class="mmc-esq__pipe" d="M 108 100 H 216" marker-end="url(#mmc-esq-seta-{{ $pid }})" />
            @if ($tanque)
                <path class="mmc-esq__pipe" d="M 252 212 V 278" marker-end="url(#mmc-esq-seta-{{ $pid }})" />
                <path class="mmc-esq__pipe" d="M 252 326 V 352 H 304" marker-end="url(#mmc-esq-seta-{{ $pid }})" />
            @else
                <path class="mmc-esq__pipe" d="M 252 212 V 352 H 304" marker-end="url(#mmc-esq-seta-{{ $pid }})" />
            @endif
            <path class="mmc-esq__pipe" d="M 376 352 H 441" marker-end="url(#mmc-esq-seta-{{ $pid }})" />
            <path class="mmc-esq__pipe" d="M 519 352 H 564 V 216" marker-end="url(#mmc-esq-seta-{{ $pid }})" />

            @if ($entradaComAgua)
                <path class="mmc-esq__flow {{ $torneiraAberta ? 'mmc-esq__flow--alerta' : '' }}" d="M 108 100 H 214" />
            @endif
            @if ($bombaATrabalhar)
                @if ($tanque)
                    <path class="mmc-esq__flow" d="M 252 212 V 276" />
                    <path class="mmc-esq__flow" d="M 252 326 V 352 H 302" />
                @else
                    <path class="mmc-esq__flow" d="M 252 212 V 352 H 302" />
                @endif
                <path class="mmc-esq__flow" d="M 376 352 H 439" />
                <path class="mmc-esq__flow" d="M 519 352 H 564 V 218" />
            @endif

            {{-- Torneira + Contador --}}
            <g
                class="mmc-esq__node mmc-esq__node--{{ $torneira['estado'] }}"
                role="button" tabindex="0" aria-label="Torneira e contador de água"
                x-on:click="toggle('torneira')" x-on:keydown.enter.prevent="toggle('torneira')"
                x-bind:class="{ 'mmc-esq__node--selecionado': aberto === 'torneira' }"
            >
                <rect x="24" y="76" width="84" height="48" rx="10" class="mmc-esq__node-caixa" />
                <path d="M 52 92 H 80 M 66 84 V 92 M 58 84 H 74" class="mmc-esq__icone" fill="none" />
                <text x="66" y="114" class="mmc-esq__node-nome">Contador</text>
                @if ($torneiraAberta)
                    <g class="mmc-esq__badge mmc-esq__badge--pulso">
                        <rect x="8" y="30" width="152" height="26" rx="13" class="mmc-esq__badge-caixa mmc-esq__badge-caixa--vermelho" />
                        <text x="84" y="47" class="mmc-esq__badge-texto">Aberta {{ $torneira['desde'] }}</text>
                    </g>
                    <g class="mmc-esq__gotas">
                        <circle cx="150" cy="112" r="3" class="mmc-esq__gota" />
                        <circle cx="170" cy="112" r="3" class="mmc-esq__gota mmc-esq__gota--2" />
                        <circle cx="190" cy="112" r="3" class="mmc-esq__gota mmc-esq__gota--3" />
                    </g>
                @elseif ($torneira['estado'] === 'desconhecido')
                    <g class="mmc-esq__badge">
                        <circle cx="108" cy="76" r="11" class="mmc-esq__badge-caixa mmc-esq__badge-caixa--cinza" />
                        <text x="108" y="81" class="mmc-esq__badge-texto">?</text>
                    </g>
                @endif
            </g>

            {{-- Piscina --}}
            <g
                class="mmc-esq__node"
                role="button" tabindex="0" aria-label="Piscina — valores da água"
                x-on:click="toggle('piscina')" x-on:keydown.enter.prevent="toggle('piscina')"
                x-bind:class="{ 'mmc-esq__node--selecionado': aberto === 'piscina' }"
            >
                <rect x="216" y="56" width="384" height="156" rx="8"
                      class="mmc-esq__piscina-borda {{ $agua['algum_mau'] ? 'mmc-esq__piscina-borda--mau' : '' }}" />
                <clipPath id="mmc-esq-agua-clip-{{ $pid }}">
                    <rect x="220" y="60" width="376" height="148" rx="6" />
                </clipPath>
                <g clip-path="url(#mmc-esq-agua-clip-{{ $pid }})">
                    <rect x="220" y="74" width="376" height="134"
                          class="mmc-esq__agua {{ $agua['origem'] === null ? 'mmc-esq__agua--sem-dados' : '' }} {{ $agua['algum_mau'] ? 'mmc-esq__agua--mau' : '' }}" />
                    <path class="mmc-esq__onda {{ $torneiraAberta ? 'mmc-esq__onda--enchendo' : '' }}"
                          d="M 200 76 q 10 -6 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 t 20 0 V 60 H 200 Z" />
                </g>
                <text x="408" y="118" class="mmc-esq__piscina-nome">{{ $piscina->name }}</text>
                <g class="mmc-esq__valores">
                    @foreach ($agua['valores'] as $i => $v)
                        <text x="{{ 288 + $i * 120 }}" y="152" class="mmc-esq__valor-label">{{ $v['label'] }}</text>
                        <text x="{{ 288 + $i * 120 }}" y="176"
                              class="mmc-esq__valor {{ $v['ok'] === false ? 'mmc-esq__valor--mau' : '' }} {{ $v['ok'] === null ? 'mmc-esq__valor--neutro' : '' }}">
                            {{ $v['valor'] }}
                        </text>
                    @endforeach
                </g>
                @if ($agua['origem'] !== null)
                    <text x="408" y="200" class="mmc-esq__origem {{ $agua['stale'] ? 'mmc-esq__origem--stale' : '' }}">
                        {{ $agua['origem'] }} · {{ $agua['atualizado'] }}
                    </text>
                @else
                    <text x="408" y="200" class="mmc-esq__origem mmc-esq__origem--stale">Sem dados recentes</text>
                @endif
            </g>

            {{-- Tanque de compensação (opcional) --}}
            @if ($tanque)
                <g
                    class="mmc-esq__node mmc-esq__node--{{ $tanque['estado'] }}"
                    role="button" tabindex="0" aria-label="Tanque de compensação"
                    x-on:click="toggle('tanque')" x-on:keydown.enter.prevent="toggle('tanque')"
                    x-bind:class="{ 'mmc-esq__node--selecionado': aberto === 'tanque' }"
                >
                    <rect x="222" y="282" width="60" height="44" rx="8" class="mmc-esq__node-caixa" />
                    <rect x="228" y="298" width="48" height="22" rx="4" class="mmc-esq__agua-mini" />
                    <text x="176" y="308" class="mmc-esq__node-nome">Tanque</text>
                    @if ($tanque['estado'] === 'verificar')
                        <g class="mmc-esq__badge">
                            <circle cx="282" cy="282" r="11" class="mmc-esq__badge-caixa mmc-esq__badge-caixa--amarelo" />
                            <text x="282" y="287" class="mmc-esq__badge-texto">!</text>
                        </g>
                    @elseif ($tanque['estado'] === 'desconhecido')
                        <g class="mmc-esq__badge">
                            <circle cx="282" cy="282" r="11" class="mmc-esq__badge-caixa mmc-esq__badge-caixa--cinza" />
                            <text x="282" y="287" class="mmc-esq__badge-texto">?</text>
                        </g>
                    @endif
                </g>
            @endif

            {{-- Bomba --}}
            <g
                class="mmc-esq__node mmc-esq__node--{{ $bomba['estado'] }}"
                role="button" tabindex="0" aria-label="Bomba de circulação"
                x-on:click="toggle('bomba')" x-on:keydown.enter.prevent="toggle('bomba')"
                x-bind:class="{ 'mmc-esq__node--selecionado': aberto === 'bomba' }"
            >
                <circle cx="340" cy="352" r="32" class="mmc-esq__node-caixa" />
                <g class="mmc-esq__rotor {{ $bombaATrabalhar ? 'mmc-esq__rotor--spin' : '' }}">
                    <path d="M 340 352 L 340 332 M 340 352 L 360 352 M 340 352 L 340 372 M 340 352 L 320 352"
                          class="mmc-esq__icone" fill="none" />
                    <circle cx="340" cy="352" r="5" class="mmc-esq__rotor-centro" />
                </g>
                <text x="340" y="404" class="mmc-esq__node-nome">Bomba</text>
                @if ($bomba['estado'] === 'parada')
                    <g class="mmc-esq__badge">
                        <rect x="292" y="300" width="96" height="24" rx="12" class="mmc-esq__badge-caixa mmc-esq__badge-caixa--amarelo" />
                        <text x="340" y="316" class="mmc-esq__badge-texto">Não ferrada</text>
                    </g>
                @elseif ($bomba['estado'] === 'desconhecido')
                    <g class="mmc-esq__badge">
                        <circle cx="366" cy="326" r="11" class="mmc-esq__badge-caixa mmc-esq__badge-caixa--cinza" />
                        <text x="366" y="331" class="mmc-esq__badge-texto">?</text>
                    </g>
                @endif
            </g>

            {{-- Filtro --}}
            <g
                class="mmc-esq__node"
                role="button" tabindex="0" aria-label="Filtro de areia"
                x-on:click="toggle('filtro')" x-on:keydown.enter.prevent="toggle('filtro')"
                x-bind:class="{ 'mmc-esq__node--selecionado': aberto === 'filtro' }"
            >
                <path d="M 445 330 Q 445 312 480 312 Q 515 312 515 330 V 376 Q 515 392 480 392 Q 445 392 445 376 Z"
                      class="mmc-esq__node-caixa" />
                <rect x="453" y="344" width="54" height="36" rx="4" class="mmc-esq__areia" />
                <text x="480" y="410" class="mmc-esq__node-nome">Filtro</text>
                @if ($filtro['lavado_hoje'])
                    <g class="mmc-esq__badge">
                        <rect x="434" y="288" width="92" height="24" rx="12" class="mmc-esq__badge-caixa mmc-esq__badge-caixa--azul" />
                        <text x="480" y="304" class="mmc-esq__badge-texto">Lavado hoje</text>
                    </g>
                @endif
            </g>
        </svg>
        </div>

        {{-- Bidões de dosagem --}}
        @if (! empty($bidoes))
            <div class="mmc-esq-bidoes" aria-label="Bidões de reagente de {{ $piscina->name }}">
                @foreach ($bidoes as $b)
                    @include('filament.pages.partials.esquema-bidao', ['b' => $b, 'grande' => true])
                @endforeach
                @if (auth()->user()?->hasAnyRole([\App\Constants\UserRole::ADMIN, \App\Constants\UserRole::TECNICO]))
                    <a href="{{ $esquema['url_bidoes'] }}" class="mmc-esq-bidoes__gerir">Gerir / reabastecer</a>
                @endif
            </div>
        @endif
    </div>

    {{-- Painéis de detalhe --}}
    <div class="mmc-esq__detalhes">
        <div class="mmc-esq__detalhe" x-show="aberto === 'torneira'" x-cloak>
            <h4 class="mmc-esq__detalhe-titulo">Torneira e contador de água</h4>
            <dl class="mmc-esq__detalhe-grelha">
                <div><dt>Estado</dt><dd>
                    @if ($torneiraAberta)
                        <span class="mmc-esq__tag mmc-esq__tag--vermelho">Aberta desde {{ $torneira['desde'] }}</span>
                    @elseif ($torneira['estado'] === 'com_agua')
                        <span class="mmc-esq__tag mmc-esq__tag--azul">{{ $torneira['agua_modo'] }}</span>
                    @elseif ($torneira['estado'] === 'fechada')
                        <span class="mmc-esq__tag">{{ $torneira['agua_modo'] }}</span>
                    @else
                        <span class="mmc-esq__tag">Desconhecido (registo com mais de 24h)</span>
                    @endif
                </dd></div>
                <div><dt>Última leitura do contador</dt><dd>{{ $torneira['contador'] ?? '—' }}</dd></div>
                @if ($torneira['fonte'])
                    <div><dt>Estado definido por</dt><dd>{{ $torneira['fonte']['via'] }} · {{ $torneira['fonte']['quando'] }}@if ($torneira['fonte']['por']) por {{ $torneira['fonte']['por'] }}@endif</dd></div>
                @endif
                @if ($torneira['contador_foto'])
                    <div><dt>Foto</dt><dd><a href="{{ $torneira['contador_foto'] }}" class="glightbox mmc-esq__link">Ver foto do contador</a></dd></div>
                @endif
            </dl>
            <div class="mmc-esq__ctas">
                @can('create', \App\Models\DailyRecord::class)
                    <a href="{{ $esquema['url_registar'] }}" class="mmc-esq__cta">{{ $torneiraAberta ? 'Registar fecho (completo)' : 'Novo registo' }}</a>
                @endcan
                @if (\App\Filament\Resources\OperationalActionResource::canCreate())
                    <a href="{{ $esquema['url_acoes_rapidas']['torneira'] }}" class="mmc-esq__cta mmc-esq__cta--secundario">{{ $torneiraAberta ? 'Só fechar torneira' : 'Só alterar torneira' }}</a>
                    <a href="{{ $esquema['url_acoes_rapidas']['contador'] }}" class="mmc-esq__cta mmc-esq__cta--secundario">Só ler contador</a>
                @endif
            </div>
        </div>

        <div class="mmc-esq__detalhe" x-show="aberto === 'piscina'" x-cloak>
            <h4 class="mmc-esq__detalhe-titulo">Água da piscina</h4>
            <dl class="mmc-esq__detalhe-grelha">
                @foreach ($agua['valores'] as $v)
                    <div><dt>{{ $v['label'] }}</dt><dd class="{{ $v['ok'] === false ? 'mmc-esq__detalhe-mau' : '' }}">{{ $v['valor'] }}</dd></div>
                @endforeach
                @if (! empty($agua['combinado']))
                    <div><dt>{{ $agua['combinado']['label'] }}</dt><dd class="{{ $agua['combinado']['ok'] === false ? 'mmc-esq__detalhe-mau' : '' }}">{{ $agua['combinado']['valor'] }}</dd></div>
                @endif
                <div><dt>Fonte</dt><dd>{{ $agua['origem'] ?? '—' }}@if ($agua['atualizado']) · {{ $agua['atualizado'] }}@endif</dd></div>
            </dl>
            @if (! empty($agua['artefacto']))
                <div class="mmc-esq__justif">
                    <span class="mmc-esq__justif-titulo">Leitura do controlador em artefacto</span>
                    <ul class="mmc-esq__justif-lista">
                        <li>{{ $agua['artefacto'] }} em curso — a água não circula no sensor, os valores não contam para a conformidade até estabilizar.</li>
                    </ul>
                </div>
            @endif
            @if (! empty($esquema['justificacoes']))
                <div class="mmc-esq__justif">
                    <span class="mmc-esq__justif-titulo">Ações operacionais recentes (possível justificação):</span>
                    <ul class="mmc-esq__justif-lista">
                        @foreach ($esquema['justificacoes'] as $j)
                            <li>
                                <strong>{{ $j['tipo'] }}</strong> · {{ $j['quando'] }}@if ($j['por']) · {{ $j['por'] }}@endif
                                @if ($j['observacoes']) — {{ $j['observacoes'] }}@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="mmc-esq__ctas">
                @can('create', \App\Models\DailyRecord::class)
                    <a href="{{ $esquema['url_registar'] }}" class="mmc-esq__cta">Novo registo</a>
                @endcan
                @if (\App\Filament\Resources\OperationalActionResource::canCreate())
                    <a href="{{ $esquema['url_acoes_rapidas']['agua'] }}" class="mmc-esq__cta mmc-esq__cta--secundario">Só análise rápida</a>
                @endif
            </div>
        </div>

        <div class="mmc-esq__detalhe" x-show="aberto === 'bomba'" x-cloak>
            <h4 class="mmc-esq__detalhe-titulo">Bomba de circulação</h4>
            <dl class="mmc-esq__detalhe-grelha">
                <div><dt>Estado</dt><dd>
                    @if ($bomba['estado'] === 'a_trabalhar')
                        <span class="mmc-esq__tag mmc-esq__tag--verde">Ferrada, a funcionar</span>
                    @elseif ($bomba['estado'] === 'parada')
                        <span class="mmc-esq__tag mmc-esq__tag--amarelo">Não ferrada</span>
                    @else
                        <span class="mmc-esq__tag">Desconhecido (registo com mais de 24h)</span>
                    @endif
                </dd></div>
                @if ($bomba['fonte'])
                    <div><dt>Estado definido por</dt><dd>{{ $bomba['fonte']['via'] }} · {{ $bomba['fonte']['quando'] }}@if ($bomba['fonte']['por']) por {{ $bomba['fonte']['por'] }}@endif</dd></div>
                @endif
                @if ($bomba['foto'])
                    <div><dt>Foto</dt><dd><a href="{{ $bomba['foto'] }}" class="glightbox mmc-esq__link">Ver foto da bomba</a></dd></div>
                @endif
            </dl>
            <div class="mmc-esq__ctas">
                @can('create', \App\Models\DailyRecord::class)
                    <a href="{{ $esquema['url_registar'] }}" class="mmc-esq__cta">Novo registo</a>
                @endcan
                @if (\App\Filament\Resources\OperationalActionResource::canCreate())
                    <a href="{{ $esquema['url_acoes_rapidas']['bomba'] }}" class="mmc-esq__cta mmc-esq__cta--secundario">Só bomba</a>
                @endif
            </div>
        </div>

        <div class="mmc-esq__detalhe" x-show="aberto === 'filtro'" x-cloak>
            <h4 class="mmc-esq__detalhe-titulo">Filtro</h4>
            <dl class="mmc-esq__detalhe-grelha">
                <div><dt>Última retrolavagem</dt><dd>{{ $filtro['ultima_lavagem'] ?? '—' }}@if ($filtro['lavado_hoje']) <span class="mmc-esq__tag mmc-esq__tag--azul">hoje</span>@endif</dd></div>
            </dl>
            @if (!empty($filtro['historico']))
                <div class="mmc-esq__historico">
                    <h5 class="mmc-esq__historico-titulo">Histórico de retrolavagens</h5>
                    <ul class="mmc-esq__historico-lista">
                        @foreach ($filtro['historico'] as $evento)
                            <li>
                                <span class="mmc-esq__historico-data">{{ $evento['data'] }}</span>
                                <span class="mmc-esq__historico-fonte">{{ $evento['fonte'] }}</span>
                                @if ($evento['por'])
                                    <span class="mmc-esq__historico-por">{{ $evento['por'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="mmc-esq__ctas">
                @can('create', \App\Models\DailyRecord::class)
                    <a href="{{ $esquema['url_registar'] }}" class="mmc-esq__cta">Novo registo</a>
                @endcan
                @if (\App\Filament\Resources\OperationalActionResource::canCreate())
                    <a href="{{ $esquema['url_acoes_rapidas']['filtro'] }}" class="mmc-esq__cta mmc-esq__cta--secundario">Só lavagem de filtro</a>
                @endif
            </div>
        </div>

        @if ($tanque)
            <div class="mmc-esq__detalhe" x-show="aberto === 'tanque'" x-cloak>
                <h4 class="mmc-esq__detalhe-titulo">Tanque de compensação</h4>
                <dl class="mmc-esq__detalhe-grelha">
                    <div><dt>Estado</dt><dd>
                        @if ($tanque['estado'] === 'ok')
                            <span class="mmc-esq__tag mmc-esq__tag--verde">OK</span>
                        @elseif ($tanque['estado'] === 'verificar')
                            <span class="mmc-esq__tag mmc-esq__tag--amarelo">Verificar</span>
                        @else
                            <span class="mmc-esq__tag">Desconhecido (registo com mais de 24h)</span>
                        @endif
                    </dd></div>
                    @if ($tanque['fonte'])
                        <div><dt>Estado definido por</dt><dd>{{ $tanque['fonte']['via'] }} · {{ $tanque['fonte']['quando'] }}@if ($tanque['fonte']['por']) por {{ $tanque['fonte']['por'] }}@endif</dd></div>
                    @endif
                    @if ($tanque['observacoes'])
                        <div><dt>Observações</dt><dd>{{ $tanque['observacoes'] }}</dd></div>
                    @endif
                    @if ($tanque['foto'])
                        <div><dt>Foto</dt><dd><a href="{{ $tanque['foto'] }}" class="glightbox mmc-esq__link">Ver foto do tanque</a></dd></div>
                    @endif
                </dl>
                <div class="mmc-esq__ctas">
                    @can('create', \App\Models\DailyRecord::class)
                        <a href="{{ $esquema['url_registar'] }}" class="mmc-esq__cta">Novo registo</a>
                    @endcan
                    @if (\App\Filament\Resources\OperationalActionResource::canCreate())
                        <a href="{{ $esquema['url_acoes_rapidas']['tanque'] }}" class="mmc-esq__cta mmc-esq__cta--secundario">Só tanque</a>
                    @endif
                </div>
            </div>
        @endif

        <p class="mmc-esq__dica" x-show="aberto === null">
            Toque num componente do esquema para ver o detalhe.
            @if ($registo)
                Último registo: {{ $registo->registado_em->format('d/m/Y H:i') }}.
            @else
                Ainda não há registos desta piscina.
            @endif
        </p>
    </div>
</div>
