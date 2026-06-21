<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Incident;
use App\Models\Installation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'installation_id' => Installation::factory(),
            'user_id'         => User::factory(),
            'ocorreu_em'      => now(),
            'type'            => fake()->randomElement(['avaria', 'vidro_partido', 'quimico']),
            'descricao'       => fake()->sentence(),
            'status'          => 'aberto',
        ];
    }
}
