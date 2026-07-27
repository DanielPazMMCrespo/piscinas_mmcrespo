<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Installation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Installation>
 */
class InstallationFactory extends Factory
{
    protected $model = Installation::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'morada' => fake()->address(),
            'active' => true,
        ];
    }
}
