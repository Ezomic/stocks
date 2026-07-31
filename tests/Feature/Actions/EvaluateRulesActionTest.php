<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('places a sell order when take-profit threshold is crossed', function (): void {
    Http::fake([
        '*' => Http::response([['order_id' => 'ORD-001']], 200),
    ]);

    $position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '265598',
    ]);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '115.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1)
        ->and(Order::first()->side)->toBe('sell')
        ->and(Order::first()->status)->toBeIn(['placed', 'failed']);
});

it('places a sell order when stop-loss threshold is crossed', function (): void {
    Http::fake([
        '*' => Http::response([['order_id' => 'ORD-002']], 200),
    ]);

    $position = Position::factory()->create([
        'symbol' => 'TSLA',
        'avg_buy_price' => '200.00',
        'quantity' => '5',
        'ibkr_con_id' => '76792991',
    ]);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '20.00',
        'stop_loss_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'TSLA',
        'price' => '175.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1)
        ->and(Order::first()->side)->toBe('sell');
});

it('does not place an order when price is within thresholds', function (): void {
    $position = Position::factory()->create([
        'symbol' => 'MSFT',
        'avg_buy_price' => '300.00',
        'quantity' => '3',
        'ibkr_con_id' => '272093',
    ]);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '20.00',
        'stop_loss_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'MSFT',
        'price' => '310.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('skips rule evaluation when rule is in cooldown', function (): void {
    $position = Position::factory()->create([
        'symbol' => 'NVDA',
        'avg_buy_price' => '100.00',
        'quantity' => '2',
        'ibkr_con_id' => '4815747',
    ]);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '5.00',
        'stop_loss_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
        'last_triggered_at' => CarbonImmutable::now()->subMinutes(30),
    ]);

    PriceSnapshot::create([
        'symbol' => 'NVDA',
        'price' => '120.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('falls back to the global rule when no position-level rule exists', function (): void {
    Http::fake([
        '*' => Http::response([['order_id' => 'ORD-GLOBAL']], 200),
    ]);

    $position = Position::factory()->create([
        'symbol' => 'AMD',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '4391',
    ]);

    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '8.00',
        'stop_loss_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AMD',
        'price' => '115.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('skips positions without a price snapshot', function (): void {
    Position::factory()->create([
        'symbol' => 'GME',
        'avg_buy_price' => '100.00',
        'quantity' => '1',
        'ibkr_con_id' => '36720217',
    ]);

    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '5.00',
        'stop_loss_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});
