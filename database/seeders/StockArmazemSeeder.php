<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\StockWarehouse;
use Illuminate\Database\Seeder;

/**
 * Cria uma linha de stock de armazém por produto, com quantidade inicial simbólica.
 * Serve para a página de Stock de Armazém abrir já com conteúdo em dev.
 * Os valores reais são ajustados no terreno via as ações de entrada/saída.
 */
class StockArmazemSeeder extends Seeder
{
    public function run(): void
    {
        // Quantidade inicial por defeito (litros/kg). Ajustar à realidade depois.
        $quantidadePorDefeito = 100;

        foreach (Product::all() as $produto) {
            StockWarehouse::firstOrCreate(
                ['product_id' => $produto->id],
                ['quantity' => $quantidadePorDefeito]
            );
        }
    }
}
