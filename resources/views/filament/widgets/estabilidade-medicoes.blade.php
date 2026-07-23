<x-filament-widgets::widget>
    @php($dados = $this->getDados())

    <x-filament::section icon="heroicon-o-scale">
        <x-slot name="heading">
            Estabilidade das medições — últimos {{ $dados['dias'] }} dias
        </x-slot>
        <x-slot name="description">
            Compara a variação das análises manuais com a da sonda Hanna. Se o σ manual for muito maior que o da sonda, a oscilação vem da rotina de amostragem (hora/ponto), não da água.
        </x-slot>
        @php
            $fmt = fn (?float $v, int $casas = 2): string => $v !== null ? number_format($v, $casas, ',', '') : '—';
        @endphp

        @if (empty($dados['linhas']))
            <p class="text-sm text-gray-500 dark:text-gray-400">Sem piscinas ativas.</p>
        @else
            <div class="mmc-estabilidade-wrap">
                <table class="mmc-estabilidade-tabela">
                    <thead>
                        <tr>
                            <th class="mmc-estabilidade-th-piscina">Piscina</th>
                            <th title="Análises manuais com pH no período">n manual</th>
                            <th title="Desvio-padrão do pH das análises manuais">σ pH manual</th>
                            <th title="Desvio-padrão do pH da sonda">σ pH sonda</th>
                            <th title="σ manual ÷ σ sonda — acima de 3 a suspeita é a medição">Rácio</th>
                            <th title="Média de (pH manual − pH sonda) nas análises com leitura de sonda a ±15 min">Δ pH médio vs sonda</th>
                            <th title="Desvio-padrão do cloro livre manual">σ Cl livre</th>
                            <th title="Desvio-padrão do ORP da sonda">σ ORP (mV)</th>
                            <th class="mmc-estabilidade-th-leitura">Leitura</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dados['linhas'] as $linha)
                            <tr>
                                <td class="mmc-estabilidade-th-piscina">{{ $linha['nome'] }}</td>
                                <td>{{ $linha['n_manual'] }}</td>
                                <td>{{ $fmt($linha['sigma_ph_manual']) }}</td>
                                <td>{{ $fmt($linha['sigma_ph_sonda']) }}</td>
                                <td>{{ $linha['racio_ph'] !== null ? $fmt($linha['racio_ph'], 1).'×' : '—' }}</td>
                                <td>
                                    @if ($linha['delta_ph_medio'] !== null)
                                        {{ ($linha['delta_ph_medio'] >= 0 ? '+' : '−').$fmt(abs($linha['delta_ph_medio'])) }}
                                        <span class="mmc-estabilidade-n-pares">({{ $linha['n_pares'] }} pares)</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>{{ $fmt($linha['sigma_cl_manual']) }}</td>
                                <td>{{ $fmt($linha['sigma_orp_sonda'], 0) }}</td>
                                <td class="mmc-estabilidade-th-leitura">
                                    <span @class([
                                        'mmc-estabilidade-badge',
                                        'mmc-estabilidade-badge--success' => $linha['cor'] === 'success',
                                        'mmc-estabilidade-badge--warning' => $linha['cor'] === 'warning',
                                        'mmc-estabilidade-badge--danger' => $linha['cor'] === 'danger',
                                        'mmc-estabilidade-badge--gray' => $linha['cor'] === 'gray',
                                    ])>{{ $linha['leitura'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <style>
        .mmc-estabilidade-wrap { overflow-x: auto; }
        .mmc-estabilidade-tabela { border-collapse: collapse; width: 100%; font-size: 0.8125rem; }
        .mmc-estabilidade-tabela th, .mmc-estabilidade-tabela td { padding: 0.375rem 0.5rem; text-align: center; white-space: nowrap; }
        .mmc-estabilidade-tabela th { color: var(--gray-500, #6b7280); font-weight: 600; }
        .mmc-estabilidade-tabela td { font-variant-numeric: tabular-nums; }
        .mmc-estabilidade-th-piscina { text-align: left !important; font-weight: 600; color: inherit; }
        .mmc-estabilidade-th-leitura { text-align: left !important; }
        .mmc-estabilidade-n-pares { font-size: 0.6875rem; color: var(--gray-500, #6b7280); }
        .mmc-estabilidade-badge {
            display: inline-block;
            padding: 0.125rem 0.5rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
            white-space: normal;
        }
        .mmc-estabilidade-badge--success { background: rgba(22, 163, 74, 0.12); color: #15803d; }
        .mmc-estabilidade-badge--warning { background: rgba(245, 158, 11, 0.14); color: #b45309; }
        .mmc-estabilidade-badge--danger { background: rgba(220, 38, 38, 0.12); color: #b91c1c; }
        .mmc-estabilidade-badge--gray { background: rgba(107, 114, 128, 0.12); color: #6b7280; }
        .dark .mmc-estabilidade-badge--success { color: #4ade80; }
        .dark .mmc-estabilidade-badge--warning { color: #fbbf24; }
        .dark .mmc-estabilidade-badge--danger { color: #f87171; }
        .dark .mmc-estabilidade-badge--gray { color: #9ca3af; }
    </style>
</x-filament-widgets::widget>
