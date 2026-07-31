<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'symbol' => strtoupper(fake()->lexify('???')),
            'broker_account_id' => 'DU'.fake()->numerify('#######'),
            'account_mode' => 'paper',
            'quantity' => fake()->randomFloat(2, 1, 100),
            'avg_buy_price' => fake()->randomFloat(2, 10, 500),
            'currency' => 'USD',
            'market' => 'STK',
            'ibkr_con_id' => null,
            'notes' => null,
        ];
    }
}
