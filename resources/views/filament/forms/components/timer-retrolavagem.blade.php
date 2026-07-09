@php
    $timersJson = collect($timers)->map(fn (array $t) => [
        'campo' => $t['campo'],
        'label' => $t['label'],
        'duracaoSegundos' => $t['duracaoSegundos'],
    ])->values()->all();
@endphp
<div x-data="mmcTimerRetrolavagem(@js($timersJson))" x-cloak>
    @foreach ($timers as $t)
        <span class="sr-only">{{ $t['label'] }} — duracaoDefaultSegundos: {{ $t['duracaoSegundos'] }}</span>
    @endforeach

    <template x-for="campo in Object.keys(timers)" :key="campo">
        <div
            x-show="timers[campo].modalAberto"
            x-cloak
            style="position: fixed; inset: 0; z-index: 60; background: rgba(0,0,0,.5); display: flex; align-items: center; justify-content: center;"
            x-on:click.self="colapsar(campo)"
        >
            <div style="background: var(--surface-2, #fff); border-radius: 16px; padding: 1.5rem 1.25rem; width: 260px; text-align: center;">
                <p style="font-size: 13px; color: #6b7280; margin-bottom: 12px;" x-text="timers[campo].label"></p>
                <div style="position: relative; width: 160px; height: 160px; margin: 0 auto 16px;">
                    <svg width="160" height="160" viewBox="0 0 160 160">
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#e5e7eb" stroke-width="6"></circle>
                        <circle
                            cx="80" cy="80" r="70" fill="none" stroke="#0ea5e9" stroke-width="6"
                            stroke-linecap="round" transform="rotate(-90 80 80)"
                            stroke-dasharray="440"
                            x-bind:stroke-dashoffset="440 - (440 * progresso(campo))"
                        ></circle>
                    </svg>
                    <div style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; font-size: 30px; font-weight: 500;" x-text="formatoTempo(campo)"></div>
                </div>
                <div style="display: flex; gap: 10px; justify-content: center; margin-bottom: 10px;">
                    <button type="button" x-on:click="cancelar(campo)">Cancelar</button>
                    <button type="button" x-on:click="alternarPausa(campo)" x-text="timers[campo].pausado ? 'Retomar' : 'Pausar'"></button>
                </div>
                <button type="button" x-on:click="timers[campo].mostrarEditor = !timers[campo].mostrarEditor" style="font-size: 12px; color: #0ea5e9; border: none; background: none; cursor: pointer;">Alterar duração</button>
                <div x-show="timers[campo].mostrarEditor" style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 8px;">
                    <button type="button" x-on:click="ajustarMinutos(campo, -1)" style="width: 28px; height: 28px; padding: 0;">−</button>
                    <span style="font-size: 13px; font-weight: 500;" x-text="Math.round(timers[campo].duracaoSegundos / 60) + ' min'"></span>
                    <button type="button" x-on:click="ajustarMinutos(campo, 1)" style="width: 28px; height: 28px; padding: 0;">+</button>
                </div>
            </div>
        </div>
    </template>

    <div x-show="algumaPillVisivel()" x-cloak style="position: sticky; top: 0; z-index: 50; display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px;">
        <template x-for="campo in Object.keys(timers)" :key="campo">
            <div
                x-show="timers[campo].pillVisivel"
                x-bind:class="timers[campo].terminado ? 'mmc-timer-pill mmc-timer-pill--terminado' : 'mmc-timer-pill'"
                x-on:click="expandir(campo)"
            >
                <svg width="30" height="30" viewBox="0 0 30 30">
                    <circle cx="15" cy="15" r="12" fill="none" stroke="rgba(255,255,255,.18)" stroke-width="3"></circle>
                    <circle
                        cx="15" cy="15" r="12" fill="none" stroke="#5db8f0" stroke-width="3"
                        stroke-linecap="round" transform="rotate(-90 15 15)"
                        stroke-dasharray="75"
                        x-bind:stroke-dashoffset="75 - (75 * progresso(campo))"
                    ></circle>
                </svg>
                <div style="flex: 1;">
                    <p style="font-size: 13px; font-weight: 500; color: #fff; margin: 0;" x-text="timers[campo].label"></p>
                    <p style="font-size: 12px; color: rgba(255,255,255,.65); margin: 0;" x-text="timers[campo].terminado ? 'Terminado' : formatoTempo(campo) + ' restantes'"></p>
                </div>
            </div>
        </template>
    </div>
</div>
