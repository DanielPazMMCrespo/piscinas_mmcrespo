<div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Confirme que os valores introduzidos estão corretos. Depois de submetido, só o administrador pode alterar este registo.
    </p>

    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
            <thead class="bg-gray-50 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Piscina</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">pH</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Cl livre</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Cl total</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Temp</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach($valores as $valor)
                    <tr>
                        <td class="px-3 py-2 font-medium text-gray-900 dark:text-gray-100">{{ $valor['piscina'] }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $valor['ph'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $valor['cloro_livre'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $valor['cloro_total'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300">{{ $valor['temperatura'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
