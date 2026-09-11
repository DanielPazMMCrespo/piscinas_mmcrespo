<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DosingContainer;
use App\Models\Pool;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DosingContainerSeeder extends Seeder
{
    public function run(): void
    {
        $cloroProduct = Product::where('name', 'like', '%Hipoclorito%')->first();
        $phProduct = Product::where('name', 'like', '%Minorador%')->first();

        $config = [
            'Competição' => [
                'cloro' => ['capacidade_ml' => 1000000, 'restante_ml' => 750000], // 1000L IBC
                'ph_menos' => ['capacidade_ml' => 200000, 'restante_ml' => 160000],  // 200L bidão
            ],
            'Lazer' => [
                'cloro' => ['capacidade_ml' => 1000000, 'restante_ml' => 680000], // 1000L IBC
                'ph_menos' => ['capacidade_ml' => 200000, 'restante_ml' => 150000],  // 200L bidão
            ],
            'Infantil' => [
                'cloro' => ['capacidade_ml' => 60000, 'restante_ml' => 50000],    // 60L bidão
                'ph_menos' => ['capacidade_ml' => 25000, 'restante_ml' => 20000],    // 25L bidão
            ],
            'Maceira' => [
                'cloro' => ['capacidade_ml' => 200000, 'restante_ml' => 170000],  // 200L bidão
                'ph_menos' => ['capacidade_ml' => 60000, 'restante_ml' => 45000],    // 60L bidão
            ],
            'Caranguejeira' => [
                'cloro' => ['capacidade_ml' => 200000, 'restante_ml' => 165000],  // 200L bidão
                'ph_menos' => ['capacidade_ml' => 60000, 'restante_ml' => 50000],    // 60L bidão
            ],
        ];

        foreach ($config as $poolName => $containers) {
            $pool = Pool::where('name', $poolName)->first();
            if (! $pool) {
                continue;
            }

            foreach ($containers as $tipo => $dados) {
                $productId = $tipo === DosingContainer::TIPO_CLORO ? $cloroProduct?->id : $phProduct?->id;

                DosingContainer::firstOrCreate(
                    [
                        'pool_id' => $pool->id,
                        'tipo' => $tipo,
                    ],
                    [
                        'product_id' => $productId,
                        'capacidade_ml' => $dados['capacidade_ml'],
                        'restante_ml' => $dados['restante_ml'],
                        'alerta_percent' => 20,
                        'reabastecido_em' => now(),
                    ]
                );
            }
        }
    }
}
