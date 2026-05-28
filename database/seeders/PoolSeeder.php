<?php

namespace Database\Seeders;

use App\Models\Installation;
use App\Models\Pool;
use Illuminate\Database\Seeder;

class PoolSeeder extends Seeder
{
    public function run(): void
    {
        $leiria        = Installation::where('name', 'Leiria')->sole();
        $maceira       = Installation::where('name', 'Maceira')->sole();
        $caranguejeira = Installation::where('name', 'Caranguejeira')->sole();

        $pools = [
            [
                'installation_id' => $leiria->id,
                'name'     => 'Competição',
                'type'     => 'competicao',
                'temp_min' => 26.0,
                'temp_max' => 27.0,
            ],
            [
                'installation_id' => $leiria->id,
                'name'     => 'Lazer',
                'type'     => 'lazer',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
            [
                'installation_id' => $leiria->id,
                'name'     => 'Infantil',
                'type'     => 'infantil',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
            [
                'installation_id' => $maceira->id,
                'name'     => 'Maceira',
                'type'     => 'polivalente',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
            [
                'installation_id' => $caranguejeira->id,
                'name'     => 'Caranguejeira',
                'type'     => 'polivalente',
                'temp_min' => 28.0,
                'temp_max' => 30.0,
            ],
        ];

        foreach ($pools as $row) {
            Pool::firstOrCreate(
                ['installation_id' => $row['installation_id'], 'name' => $row['name']],
                $row + ['active' => true]
            );
        }
    }
}