<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'unidade' => fake()->randomElement(['kg', 'L', 'un']),
            'categoria' => fake()->randomElement(['quimico', 'consumivel']),
            'active' => true,
        ];
    }
}
