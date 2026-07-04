<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'position_id' => Position::factory(),
            'rule_id' => null,
            'side' => fake()->randomElement(['buy', 'sell']),
            'quantity' => fake()->randomFloat(2, 1, 100),
            'order_type' => 'market',
            'limit_price' => null,
            'status' => 'pending',
            'broker_order_id' => null,
            'placed_at' => null,
            'filled_at' => null,
            'fill_price' => null,
            'error_message' => null,
        ];
    }
}
