@php
    /** @var \App\Models\DailyRecord $record */
    $record = $getRecord();

    $fmt = static fn ($v): string => $v === null
        ? '—'
        : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');

    $metrics = [
        ['label' => 'pH', 'value' => $record->ph_efetivo, 'ok' => $record->phConforme()],
        ['label' => 'Cloro L.', 'value' => $record->cloro_livre_efetivo, 'unit' => 'mg/L', 'ok' => $record->cloroLivreConforme()],
        ['label' => 'Cloro T.', 'value' => $record->cloro_total_efetivo, 'unit' => 'mg/L', 'ok' => $record->cloroCombinadoConforme()],
        ['label' => 'Turbidez', 'value' => $record->transparencia, 'unit' => 'FNU', 'ok' => null],
    ];

    $numFora = collect($metrics)->filter(fn ($m) => $m['ok'] === false)->count();
    $temDados = collect($metrics)->contains(fn ($m) => $m['value'] !== null);
    $estadoDot = ! $temDados ? 'neutro' : ($numFora > 0 ? 'bad' : 'ok');

    if ($record->e_correcao) {
        $estadoBadge = ['label' => 'Correção', 'class' => 'mmc-record-tag--warning'];
    } elseif (($record->correcoes_count ?? 0) > 0) {
        $estadoBadge = ['label' => 'Corrigido', 'class' => 'mmc-record-tag--muted'];
    } else {
        $estadoBadge = null;
    }
@endphp

<div class="mmc-record-card @if ($estadoDot === 'bad') mmc-record-card--alert @endif">
    <div class="mmc-record-head">
        <span class="mmc-pool-dot mmc-pool-dot--{{ $estadoDot }}"></span>
        <div class="mmc-record-headings">
            <div class="mmc-record-title-row">
                <span class="mmc-record-pool">{{ $record->piscina?->name }}</span>
                @if ($estadoBadge)
                    <span class="mmc-record-tag {{ $estadoBadge['class'] }}">{{ $estadoBadge['label'] }}</span>
                @endif
            </div>
            <div class="mmc-record-meta">
                @if ($record->piscina?->instalacao?->name)
                    <span>{{ $record->piscina->instalacao->name }}</span>
                    <span class="mmc-record-meta-sep">·</span>
                @endif
                <span>{{ $record->registado_em?->format('d/m/Y H:i') }}</span>
                @if ($record->utilizador?->name)
                    <span class="mmc-record-meta-sep">·</span>
                    <span class="mmc-record-meta-user">
                        <x-filament::icon icon="heroicon-m-user" class="mmc-record-meta-icon" />
                        {{ $record->utilizador->name }}
                    </span>
                @endif
            </div>
        </div>
    </div>

    <div class="mmc-record-metrics">
        @foreach ($metrics as $m)
            <div class="mmc-record-chip @if ($m['ok'] === false) mmc-record-chip--bad @elseif ($m['ok'] === true) mmc-record-chip--ok @endif">
                <span class="mmc-record-chip-label">{{ $m['label'] }}</span>
                <span class="mmc-record-chip-value">
                    {{ $fmt($m['value']) }}@if ($m['value'] !== null && ! empty($m['unit']))<span class="mmc-record-chip-unit">{{ $m['unit'] }}</span>@endif
                </span>
            </div>
        @endforeach
    </div>
</div>
