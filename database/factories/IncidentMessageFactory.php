<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Incident;
use App\Models\IncidentMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentMessage>
 */
class IncidentMessageFactory extends Factory
{
    protected $model = IncidentMessage::class;

    public function definition(): array
    {
        return [
            'incident_id' => Incident::factory(),
            'user_id' => User::factory(),
            'tipo' => IncidentMessage::TIPO_MENSAGEM,
            'texto' => fake()->sentence(),
        ];
    }
}
