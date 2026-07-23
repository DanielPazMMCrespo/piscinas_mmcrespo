@php
    $grande = $grande ?? false;
    $pct = $b['pct'];
@endphp
<div style="display: flex; flex-direction: column; gap: 0.5rem; {{ $grande ? 'min-width: 130px;' : 'min-width: 50px;' }}">
    <div class="mmc-jerry mmc-jerry--{{ $b['nivel'] }} {{ $grande ? 'mmc-jerry--grande' : '' }}"
         title="{{ $b['label'] }}: {{ $pct !== null ? number_format($pct, 0, ',', '') . '%' : 'sem capacidade definida' }}">
        <div class="mmc-jerry__body">
            <div class="mmc-jerry__fill" style="height: {{ $pct !== null ? $pct : 0 }}%"></div>
        </div>
        <span class="mmc-jerry__label">{{ $b['label'] }}</span>
        <span class="mmc-jerry__pct">{{ $pct !== null ? number_format($pct, 0, ',', '') . '%' : '—' }}</span>
        <span class="mmc-jerry__lit">{{ $b['restante_l'] }}@if ($b['capacidade_l']) / {{ $b['capacidade_l'] }}@endif L</span>
    </div>

    @if ($grande && !empty($b['ultimos_consumos']))
        <div class="mmc-jerry__logs" style="margin-top: 0.25rem; font-size: 0.75rem; text-align: left; width: 100%; color: #64748b; line-height: 1.3;">
            <strong style="display: block; margin-bottom: 0.3rem; font-size: 0.75rem; color: #334155;">Últimos Débitos:</strong>
            @foreach ($b['ultimos_consumos'] as $log)
                <div style="display: flex; justify-content: space-between; gap: 0.5rem; border-top: 1px solid var(--mmc-jerry-border); padding: 0.2rem 0;">
                    <span>{{ $log['registado_em'] }}</span>
                    <span style="font-weight: 600; color: #ef4444;" title="Origem: {{ $log['origem'] }}">{{ number_format($log['quantidade_ml'], 0) }} ml</span>
                </div>
            @endforeach
        </div>
    @endif
</div>

