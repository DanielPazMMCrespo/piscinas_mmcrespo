@php
    $m1 = $data['pressao_manometro_1'] ?? null;
    $m2 = $data['pressao_manometro_2'] ?? null;
    $diverge = is_numeric($m1) && is_numeric($m2)
        && abs((float) $m1 - (float) $m2) >= \App\Models\DailyRecord::PRESSAO_DIVERGENCIA_MAX;
@endphp

<div class="rd-grid-2">
    <div>
        <span class="rd-field-label">Manómetro 1 (esquerdo) · bar</span>
        <x-filament::input.wrapper>
            <x-filament::input type="number" step="0.01" min="0" max="10" inputmode="decimal"
                wire:model.blur="data.pressao_manometro_1" placeholder="Lido por IA" />
        </x-filament::input.wrapper>
    </div>
    <div>
        <span class="rd-field-label">Manómetro 2 (direito) · bar</span>
        <x-filament::input.wrapper>
            <x-filament::input type="number" step="0.01" min="0" max="10" inputmode="decimal"
                wire:model.blur="data.pressao_manometro_2" placeholder="Lido por IA" />
        </x-filament::input.wrapper>
    </div>
</div>

@if ($diverge)
    <p class="rd-warn">⚠ Os manómetros divergem {{ \App\Models\DailyRecord::PRESSAO_DIVERGENCIA_MAX }} bar ou mais — recomenda-se retrolavagem.</p>
@endif

<div>
    <span class="rd-field-label">Vais fazer retrolavagem?</span>
    <div class="rd-pills cols-2">
        <button type="button" class="rd-pill {{ ($data['fez_retrolavagem'] ?? false) ? 'is-active' : '' }}"
            wire:click="$set('data.fez_retrolavagem', true)">Sim</button>
        <button type="button" class="rd-pill {{ ! ($data['fez_retrolavagem'] ?? false) ? 'is-active' : '' }}"
            wire:click="$set('data.fez_retrolavagem', false)">Não</button>
    </div>
    <p class="rd-note">Se sim, os passos da retrolavagem (lavagem, enxaguamento, posição normal) aparecem a seguir.</p>
</div>
