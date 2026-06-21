<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\FilterCheck;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FilterCheck>
 */
class FilterCheckFactory extends Factory
{
    protected $model = FilterCheck::class;

    public function definition(): array
    {
        return [
            'pool_id'       => Pool::factory(),
            'user_id'       => User::factory(),
            'verificado_em' => now(),
            'tipo_operacao' => fake()->randomElement(['lavagem', 'enxaguamento', 'posicao_normal']),
        ];
    }
}
