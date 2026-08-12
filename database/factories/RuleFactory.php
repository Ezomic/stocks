<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    protected $model = Rule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'position_id' => null,
            'take_profit_pct' => fake()->randomFloat(2, 5, 50),
            'stop_loss_pct' => fake()->randomFloat(2, 5, 20),
            'stop_loss_type' => 'entry',
            'sell_pct' => 100,
            'is_active' => true,
            'cooldown_minutes' => 60,
        ];
    }
}
