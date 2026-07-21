@php
    $grande = $grande ?? false;
    $pct = $b['pct'];
@endphp
<div class="mmc-jerry mmc-jerry--{{ $b['nivel'] }} {{ $grande ? 'mmc-jerry--grande' : '' }}"
     title="{{ $b['label'] }}: {{ $pct !== null ? number_format($pct, 0, ',', '') . '%' : 'sem capacidade definida' }}">
    <div class="mmc-jerry__body">
        <div class="mmc-jerry__fill" style="height: {{ $pct !== null ? $pct : 0 }}%"></div>
    </div>
    <span class="mmc-jerry__label">{{ $b['label'] }}</span>
    <span class="mmc-jerry__pct">{{ $pct !== null ? number_format($pct, 0, ',', '') . '%' : '—' }}</span>
    <span class="mmc-jerry__lit">{{ $b['restante_l'] }}@if ($b['capacidade_l']) / {{ $b['capacidade_l'] }}@endif L</span>
</div>
