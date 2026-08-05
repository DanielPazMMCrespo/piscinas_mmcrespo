<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Constants\PoolAccessRequestStatus;
use App\Models\PoolAccessRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PoolAccessRequest>
 */
class PoolAccessRequestFactory extends Factory
{
    protected $model = PoolAccessRequest::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'motivo' => 'Preciso de verificar um registo antes de amanhã.',
            'status' => PoolAccessRequestStatus::PENDENTE,
        ];
    }

    public function aprovado(): self
    {
        return $this->state(fn () => [
            'status' => PoolAccessRequestStatus::APROVADO,
            'decidido_por' => User::factory(),
            'decidido_em' => now(),
        ]);
    }

    public function negado(): self
    {
        return $this->state(fn () => [
            'status' => PoolAccessRequestStatus::NEGADO,
            'decidido_por' => User::factory(),
            'decidido_em' => now(),
        ]);
    }
}
