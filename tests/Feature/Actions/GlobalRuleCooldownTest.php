<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();
    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);
});

function winningPosition(string $symbol): Position
{
    $position = Position::factory()->create([
        'symbol' => $symbol,
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => (string) crc32($symbol),
    ]);

    PriceSnapshot::create([
        'symbol' => $symbol,
        'price' => '120.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    return $position;
}

it('triggers every position under one global rule in the same cycle', function (): void {
    winningPosition('AAA');
    winningPosition('BBB');
    winningPosition('CCC');

    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(3);
});

it('does not let one position put another position into cooldown', function (): void {
    $first = winningPosition('AAA');
    $second = winningPosition('BBB');

    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    $first->update(['last_triggered_at' => CarbonImmutable::now()->subMinutes(5)]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::where('position_id', $first->id)->count())->toBe(0)
        ->and(Order::where('position_id', $second->id)->count())->toBe(1);
});

it('stamps the cooldown on the position rather than the rule', function (): void {
    $position = winningPosition('AAA');

    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect($position->fresh()->last_triggered_at)->not->toBeNull();
});

it('rejects a second global rule', function (): void {
    Rule::factory()->create(['position_id' => null]);

    $this->actingAs(User::factory()->create())
        ->post('/rules', [
            'take_profit_pct' => '5',
            'stop_loss_pct' => '5',
            'cooldown_minutes' => '60',
            'is_active' => '1',
        ])
        ->assertSessionHasErrors('position_id');

    expect(Rule::whereNull('position_id')->count())->toBe(1);
});

it('rejects a second rule for the same position', function (): void {
    $position = Position::factory()->create();
    Rule::factory()->create(['position_id' => $position->id]);

    $this->actingAs(User::factory()->create())
        ->post('/rules', [
            'position_id' => (string) $position->id,
            'take_profit_pct' => '5',
            'cooldown_minutes' => '60',
            'is_active' => '1',
        ])
        ->assertSessionHasErrors('position_id');

    expect(Rule::where('position_id', $position->id)->count())->toBe(1);
});

it('still allows editing the existing global rule', function (): void {
    $rule = Rule::factory()->create(['position_id' => null, 'cooldown_minutes' => 60]);

    $this->actingAs(User::factory()->create())
        ->put("/rules/{$rule->id}", [
            'take_profit_pct' => '12',
            'stop_loss_pct' => '6',
            'cooldown_minutes' => '30',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect((int) $rule->fresh()->cooldown_minutes)->toBe(30);
});

it('still allows editing an existing position rule', function (): void {
    $position = Position::factory()->create();
    $rule = Rule::factory()->create(['position_id' => $position->id, 'cooldown_minutes' => 60]);

    $this->actingAs(User::factory()->create())
        ->put("/rules/{$rule->id}", [
            'position_id' => (string) $position->id,
            'take_profit_pct' => '12',
            'cooldown_minutes' => '30',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect((int) $rule->fresh()->cooldown_minutes)->toBe(30);
});
