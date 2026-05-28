<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Hipoclorito de Sódio',      'unidade' => 'L',  'categoria' => 'Desinfeção'],
            ['name' => 'Minorador de pH',            'unidade' => 'kg', 'categoria' => 'Correção pH'],
            ['name' => 'Floculante Líquido',         'unidade' => 'L',  'categoria' => 'Clarificação'],
            ['name' => 'Algicida',                   'unidade' => 'L',  'categoria' => 'Tratamento'],
            ['name' => 'Germicida',                  'unidade' => 'L',  'categoria' => 'Desinfeção'],
            ['name' => 'Dicloro Granulado',          'unidade' => 'kg', 'categoria' => 'Desinfeção'],
            ['name' => 'Tricloro Granulado',         'unidade' => 'kg', 'categoria' => 'Desinfeção'],
            ['name' => 'Desincrustante de Bordas',   'unidade' => 'L',  'categoria' => 'Manutenção'],
        ];

        foreach ($products as $row) {
            Product::firstOrCreate(['name' => $row['name']], $row + ['active' => true]);
        }
    }
}