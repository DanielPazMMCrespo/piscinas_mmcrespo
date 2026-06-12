<div>
    <span class="rd-field-label">Estado da bomba</span>
    <div class="rd-pills cols-2">
        @foreach (\App\Models\DailyRecord::BOMBA_ESTADOS as $valor => $rotulo)
            <button type="button"
                class="rd-pill {{ ($data['bomba_estado'] ?? null) === $valor ? 'is-active' : '' }}"
                wire:click="$set('data.bomba_estado', '{{ $valor }}')">
                {{ $rotulo }}
            </button>
        @endforeach
    </div>
    <p class="rd-note">Ferrada = há água a circular. Desferrada = sem circulação.</p>
</div>
