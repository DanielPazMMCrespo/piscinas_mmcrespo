<x-filament-widgets::widget>
    <x-filament::section heading="Conformidade — últimos 7 dias" icon="heroicon-o-squares-2x2">
        @php($dados = $this->getDados())

        @if (empty($dados['piscinas']))
            <p class="text-sm text-gray-500 dark:text-gray-400">Sem piscinas ativas.</p>
        @else
            <div class="mmc-heatmap-wrap">
                <table class="mmc-heatmap-tabela">
                    <thead>
                        <tr>
                            <th class="mmc-heatmap-th-piscina"></th>
                            @foreach ($dados['dias'] as $dia)
                                <th>{{ $dia }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($dados['piscinas'] as $linha)
                            <tr>
                                <td class="mmc-heatmap-th-piscina">{{ $linha['nome'] }}</td>
                                @foreach ($linha['celulas'] as $celula)
                                    <td>
                                        <span
                                            class="mmc-heatmap-celula"
                                            style="background-color: {{ $celula['cor'] }};"
                                            title="{{ $celula['titulo'] }}"
                                        ></span>
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mmc-heatmap-legenda">
                <span><span class="mmc-heatmap-celula" style="background-color:#16a34a;"></span> Conforme</span>
                <span><span class="mmc-heatmap-celula" style="background-color:#f59e0b;"></span> Ligeiro desvio</span>
                <span><span class="mmc-heatmap-celula" style="background-color:#dc2626;"></span> Não conforme</span>
                <span><span class="mmc-heatmap-celula" style="background-color:#e5e7eb;"></span> Sem registos</span>
            </div>
        @endif
    </x-filament::section>

    <style>
        .mmc-heatmap-wrap { overflow-x: auto; }
        .mmc-heatmap-tabela { border-collapse: collapse; width: 100%; font-size: 0.8125rem; }
        .mmc-heatmap-tabela th, .mmc-heatmap-tabela td { padding: 0.375rem 0.5rem; text-align: center; }
        .mmc-heatmap-tabela th { color: var(--gray-500, #6b7280); font-weight: 600; white-space: nowrap; }
        .mmc-heatmap-th-piscina { text-align: left !important; white-space: nowrap; font-weight: 600; color: inherit; }
        .mmc-heatmap-celula {
            display: inline-block;
            width: 1.75rem;
            height: 1.75rem;
            border-radius: 0.375rem;
        }
        .mmc-heatmap-legenda {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 0.75rem;
            font-size: 0.75rem;
            color: var(--gray-500, #6b7280);
            align-items: center;
        }
        .mmc-heatmap-legenda span { display: inline-flex; align-items: center; gap: 0.375rem; }
        .mmc-heatmap-legenda .mmc-heatmap-celula { width: 0.875rem; height: 0.875rem; border-radius: 0.25rem; }
    </style>
</x-filament-widgets::widget>
