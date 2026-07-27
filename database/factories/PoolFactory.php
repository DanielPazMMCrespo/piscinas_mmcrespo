<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Installation;
use App\Models\Pool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pool>
 */
class PoolFactory extends Factory
{
    protected $model = Pool::class;

    public function definition(): array
    {
        return [
            'installation_id' => Installation::factory(),
            'name' => fake()->randomElement(['Competição', 'Lazer', 'Infantil']),
            'type' => fake()->randomElement(['competicao', 'lazer', 'infantil']),
            'temp_min' => 26.0,
            'temp_max' => 30.0,
            'volume' => fake()->numberBetween(50, 900),
            'active' => true,
        ];
    }
}
