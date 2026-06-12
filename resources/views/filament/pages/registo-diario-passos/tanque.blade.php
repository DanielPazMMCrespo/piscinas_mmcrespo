<div>
    <span class="rd-field-label">Nível da água no tanque (%)</span>
    <x-filament::input.wrapper suffix="%">
        <x-filament::input type="number" step="1" min="0" max="100" inputmode="numeric"
            wire:model.blur="data.tanque_nivel" placeholder="Lido por IA" />
    </x-filament::input.wrapper>
    <p class="rd-note">0 = vazio, 100 = cheio.</p>
</div>
