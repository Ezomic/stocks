<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ManualOrder;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManualOrder>
 */
class ManualOrderFactory extends Factory
{
    protected $model = ManualOrder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'position_id' => Position::factory(),
            'symbol' => strtoupper(fake()->lexify('???')),
            'side' => fake()->randomElement(ManualOrder::SIDES),
            'quantity' => fake()->numberBetween(1, 50),
            'price' => fake()->randomFloat(2, 10, 500),
            'fees' => 1,
            'currency' => 'USD',
            'order_date' => now()->toDateString(),
            'note' => null,
            'status' => ManualOrder::STATUS_PLANNED,
        ];
    }
}
