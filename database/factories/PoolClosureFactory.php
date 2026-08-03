<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Constants\MotivoEncerramento;
use App\Models\Pool;
use App\Models\PoolClosure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PoolClosure>
 */
class PoolClosureFactory extends Factory
{
    protected $model = PoolClosure::class;

    public function definition(): array
    {
        return [
            'pool_id' => Pool::factory(),
            'inicio' => Carbon::now()->startOfDay(),
            'fim' => null,
            'motivo' => MotivoEncerramento::EPOCA_BALNEAR,
            'agua_em_tratamento' => false,
            'observacoes' => null,
        ];
    }

    public function comTratamento(): self
    {
        return $this->state(fn () => ['agua_em_tratamento' => true]);
    }

    public function periodo(Carbon $inicio, ?Carbon $fim = null): self
    {
        return $this->state(fn () => [
            'inicio' => $inicio->copy()->startOfDay(),
            'fim' => $fim?->copy()->startOfDay(),
        ]);
    }
}
