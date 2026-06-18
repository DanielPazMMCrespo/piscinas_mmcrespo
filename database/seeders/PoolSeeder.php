<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Installation;
use App\Models\Pool;
use Illuminate\Database\Seeder;

class PoolSeeder extends Seeder
{
    public function run(): void
    {
        $leiria = Installation::where('name', 'Leiria')->sole();
        $maceira = Installation::where('name', 'Maceira')->sole();
        $caranguejeira = Installation::where('name', 'Caranguejeira')->sole();

        // Volumes (m³) conforme contrato MMCrespo — necessários para a calculadora
        // de dosagem de químicos (DailyRecordResource: volume × dosagem ÷ concentração).
        $pools = [
            [
                'installation_id' => $leiria->id,
                'name' => 'Competição',
                'type' => 'competicao',
                'temp_min' => 26.0,
                'temp_max' => 27.0,
                'volume' => 900.0,
            ],
            [
                'installation_id' => $leiria->id,
                'name' => 'Lazer',
                'type' => 'lazer',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
                'volume' => 600.0,
            ],
            [
                'installation_id' => $leiria->id,
                'name' => 'Infantil',
                'type' => 'infantil',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
                'volume' => 50.0,
            ],
            [
                'installation_id' => $maceira->id,
                'name' => 'Maceira',
                'type' => 'polivalente',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
                'volume' => 170.0,
            ],
            [
                'installation_id' => $caranguejeira->id,
                'name' => 'Caranguejeira',
                'type' => 'polivalente',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
                'volume' => 170.0,
            ],
        ];

        foreach ($pools as $row) {
            $piscina = Pool::firstOrCreate(
                ['installation_id' => $row['installation_id'], 'name' => $row['name']],
                $row + ['active' => true]
            );

            // Preenche o volume em piscinas já existentes (seed anterior sem volume).
            if ($piscina->volume === null) {
                $piscina->update(['volume' => $row['volume']]);
            }
        }
    }
}
