<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DailyRecord;
use App\Models\Pool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyRecord>
 */
class DailyRecordFactory extends Factory
{
    protected $model = DailyRecord::class;

    public function definition(): array
    {
        return [
            'pool_id' => Pool::factory(),
            'user_id' => User::factory(),
            'registado_em' => now(),
            'cloro_livre' => 1.00,
            'cloro_total' => 1.20,
            'ph' => 7.40,
            'temperatura' => 27.0,
            'transparencia' => 1,
            'caleira_feita' => true,
            'renovacao_agua' => false,
            'e_correcao' => false,
        ];
    }
}
