<div>
    <span class="rd-field-label">Leitura do contador (m³)</span>
    <x-filament::input.wrapper>
        <x-filament::input type="number" step="0.01" min="0" inputmode="decimal"
            wire:model.blur="data.contador_leitura" placeholder="Lido por IA" />
    </x-filament::input.wrapper>
</div>

<div>
    <span class="rd-field-label">Torneira da água da rede</span>
    <div class="rd-pills cols-3">
        @foreach (\App\Models\DailyRecord::TORNEIRA_MODOS as $valor => $rotulo)
            <button type="button"
                class="rd-pill {{ ($data['torneira_modo'] ?? null) === $valor ? 'is-active' : '' }}"
                wire:click="$set('data.torneira_modo', '{{ $valor }}')">
                {{ $rotulo }}
            </button>
        @endforeach
    </div>
    @if (($data['torneira_modo'] ?? null) === 'ligada')
        <p class="rd-warn">⚠ Torneira ligada: ao guardar, técnico e admin recebem um alerta de água aberta nesta piscina.</p>
    @endif
</div>

<div>
    <span class="rd-field-label">Está com água a passar?</span>
    <div class="rd-pills cols-2">
        <button type="button" class="rd-pill {{ ($data['torneira_com_agua'] ?? false) ? 'is-active' : '' }}"
            wire:click="$set('data.torneira_com_agua', true)">Sim</button>
        <button type="button" class="rd-pill {{ ! ($data['torneira_com_agua'] ?? false) ? 'is-active' : '' }}"
            wire:click="$set('data.torneira_com_agua', false)">Não</button>
    </div>
</div>
