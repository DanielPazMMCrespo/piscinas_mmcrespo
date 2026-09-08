<?php

declare(strict_types=1);

use App\Models\Installation;
use App\Models\Pool;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $leiria = Installation::where('name', 'Leiria')->first();
        if (! $leiria) {
            return;
        }

        // 1. Entrada: Bomba e Contador Infantil -> Filtro Infantil
        Pool::where('installation_id', $leiria->id)->where('name', 'Infantil')
            ->update(['ordem_bombas' => 1, 'ordem_filtros' => 1]);

        // 2. Filtro Lazer
        // 5. Bomba e Contador Lazer
        Pool::where('installation_id', $leiria->id)->where('name', 'Lazer')
            ->update(['ordem_bombas' => 3, 'ordem_filtros' => 2]);

        // 3. Filtro Competição
        // 4. Bomba e Contador Competição
        Pool::where('installation_id', $leiria->id)->where('name', 'Competição')
            ->update(['ordem_bombas' => 2, 'ordem_filtros' => 3]);
    }

    public function down(): void
    {
        Pool::query()->update(['ordem_bombas' => 0, 'ordem_filtros' => 0]);
    }
};
