<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DailyRecord;
use App\Models\Product;
use App\Models\RecordAddition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordAddition>
 */
class RecordAdditionFactory extends Factory
{
    protected $model = RecordAddition::class;

    public function definition(): array
    {
        return [
            'daily_record_id' => DailyRecord::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(3, 0.1, 10),
        ];
    }
}
