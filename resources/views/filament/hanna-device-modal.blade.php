@php
    $leitura = $device->ultimaLeitura();
    $raw = $device->raw_info ?? [];
    $reported = $raw['reportedSettings'] ?? [];
    $sy = isset($reported['SY']) ? explode(',', $reported['SY']) : [];
    $serial   = $sy[4] ?? null;
    $firmware = isset($sy[2], $sy[3]) ? trim(str_replace('&#47;', '/', $sy[2] . $sy[3])) : null;
    $dinfo    = $raw['DINFO'] ?? [];
    $extras   = array_filter($reported, fn ($k) => $k !== 'SY', ARRAY_FILTER_USE_KEY);
@endphp

<div class="space-y-5 px-1 py-2">

    {{-- Última Leitura --}}
    <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Última Leitura</p>
        @if ($leitura)
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                @foreach ([
                    ['label' => 'pH',            'value' => $leitura->ph    !== null ? number_format($leitura->ph, 2, ',', '')    : null, 'unit' => ''],
                    ['label' => 'ORP',           'value' => $leitura->orp   !== null ? number_format($leitura->orp, 0, ',', '')   : null, 'unit' => ' mV'],
                    ['label' => 'T° Água',        'value' => $leitura->temperatura_agua !== null ? number_format($leitura->temperatura_agua, 1, ',', '') : null, 'unit' => ' °C'],
                    ['label' => 'T° Ar',          'value' => $leitura->temperatura_ar   !== null ? number_format($leitura->temperatura_ar,   1, ',', '') : null, 'unit' => ' °C'],
                    ['label' => 'Caudal pH',      'value' => $leitura->caudal_ph    !== null ? number_format($leitura->caudal_ph,    1, ',', '') : null, 'unit' => ' mL/h'],
                    ['label' => 'Caudal Cloro',   'value' => $leitura->caudal_cloro !== null ? number_format($leitura->caudal_cloro, 1, ',', '') : null, 'unit' => ' mL/h'],
                ] as $m)
                    <div class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2">
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $m['label'] }}</p>
                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 mt-0.5">
                            {{ $m['value'] !== null ? $m['value'] . $m['unit'] : '—' }}
                        </p>
                    </div>
                @endforeach
            </div>
            <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                Registado em: {{ $leitura->lida_em?->format('d/m/Y H:i') ?? '—' }}
            </p>
        @else
            <p class="text-sm text-gray-400 dark:text-gray-500">Sem leituras registadas.</p>
        @endif
    </div>

    <hr class="border-gray-100 dark:border-white/10">

    {{-- Info do Dispositivo --}}
    <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">Informação do Dispositivo</p>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5">
            @foreach ([
                ['Device ID',  $device->hanna_device_id],
                ['Modelo',     $raw['DM'] ?? null],
                ['Série',      $serial],
                ['Firmware',   $firmware],
                ['Piscina',    $device->piscina?->name],
                ['Tank Hanna', $dinfo['tankName'] ?? null],
            ] as [$label, $value])
                @if ($value !== null)
                    <div class="contents">
                        <dt class="text-xs text-gray-400 dark:text-gray-500 self-center">{{ $label }}</dt>
                        <dd class="text-xs font-medium text-gray-700 dark:text-gray-200 self-center truncate">{{ $value }}</dd>
                    </div>
                @endif
            @endforeach
        </dl>
    </div>

    {{-- Reported Settings (setpoints e config completa) --}}
    @if (!empty($extras))
        <hr class="border-gray-100 dark:border-white/10">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-2">
                Setpoints e Configuração
                @if (!empty($raw['lastUpdated']))
                    <span class="font-normal normal-case">— obtidos em {{ \Illuminate\Support\Carbon::parse($raw['lastUpdated'])->format('d/m/Y H:i') }}</span>
                @endif
            </p>
            <div class="rounded-lg bg-gray-50 dark:bg-white/5 overflow-x-auto">
                <table class="w-full text-xs">
                    @foreach ($extras as $key => $val)
                        <tr class="border-b border-gray-100 dark:border-white/5 last:border-0">
                            <td class="px-3 py-1.5 text-gray-400 dark:text-gray-500 font-mono w-1/3 align-top">{{ $key }}</td>
                            <td class="px-3 py-1.5 text-gray-700 dark:text-gray-200 font-mono break-all">
                                @if (is_array($val) || is_object($val))
                                    {{ json_encode($val, JSON_UNESCAPED_UNICODE) }}
                                @else
                                    {{ $val }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
            <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                Para atualizar estes valores corre <code class="bg-gray-100 dark:bg-white/10 px-1 rounded">php artisan hanna:sync</code>.
            </p>
        </div>
    @else
        <p class="text-xs text-gray-400 dark:text-gray-500">
            Sem configuração guardada. Corre "Sincronizar agora" para obter os setpoints.
        </p>
    @endif

</div>
