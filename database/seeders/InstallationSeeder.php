<?php

namespace Database\Seeders;

use App\Models\Installation;
use Illuminate\Database\Seeder;

class InstallationSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['name' => 'Leiria',          'morada' => 'Complexo de Piscinas de Leiria, Leiria'],
            ['name' => 'Maceira',         'morada' => 'Piscina Municipal de Maceira, Maceira, Leiria'],
            ['name' => 'Caranguejeira',   'morada' => 'Piscina Municipal de Caranguejeira, Caranguejeira, Leiria'],
        ];

        foreach ($data as $row) {
            Installation::firstOrCreate(['name' => $row['name']], $row + ['active' => true]);
        }
    }
}