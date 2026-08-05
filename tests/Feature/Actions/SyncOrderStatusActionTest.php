<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncOrderStatusAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** @param array<string, mixed> $overrides */
function brokerOrders(array $overrides = []): void
{
    Http::fake([
        '*' => Http::response([
            'orders' => [array_merge([
                'orderId' => 'ORD-001',
                'status' => 'Filled',
                'avgPrice' => '115.00',
            ], $overrides)],
        ], 200),
    ]);
}

function placedSellOrder(Position $position, string $quantity = '10'): Order
{
    return Order::factory()->create([
        'position_id' => $position->id,
        'side' => 'sell',
        'quantity' => $quantity,
        'status' => 'placed',
        'broker_order_id' => 'ORD-001',
    ]);
}

it('reduces the position quantity when a sell order fills', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10']);
    placedSellOrder($position);
    brokerOrders();

    app(SyncOrderStatusAction::class)->handle();

    expect((float) $position->fresh()->quantity)->toBe(0.0);
});

it('increases the position quantity when a buy order fills', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10']);
    Order::factory()->create([
        'position_id' => $position->id,
        'side' => 'buy',
        'quantity' => '5',
        'status' => 'placed',
        'broker_order_id' => 'ORD-001',
    ]);
    brokerOrders();

    app(SyncOrderStatusAction::class)->handle();

    expect((float) $position->fresh()->quantity)->toBe(15.0);
});

it('applies the broker filled quantity on a partial fill', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10']);
    placedSellOrder($position);
    brokerOrders(['filledQuantity' => 4]);

    app(SyncOrderStatusAction::class)->handle();

    expect((float) $position->fresh()->quantity)->toBe(6.0);
});

it('never drives a position quantity below zero', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '3']);
    placedSellOrder($position, '10');
    brokerOrders();

    app(SyncOrderStatusAction::class)->handle();

    expect((float) $position->fresh()->quantity)->toBe(0.0);
});

it('leaves the position quantity alone when an order is cancelled', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10']);
    placedSellOrder($position);
    brokerOrders(['status' => 'Cancelled']);

    app(SyncOrderStatusAction::class)->handle();

    expect((float) $position->fresh()->quantity)->toBe(10.0)
        ->and(Order::first()->status)->toBe('cancelled');
});

it('does not apply the same fill twice', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10']);
    placedSellOrder($position, '4');
    brokerOrders(['filledQuantity' => 4]);

    app(SyncOrderStatusAction::class)->handle();
    app(SyncOrderStatusAction::class)->handle();

    expect((float) $position->fresh()->quantity)->toBe(6.0);
});

it('does not sell the same holding again after the sell has filled', function (): void {
    Http::fake([
        '*/iserver/account/*/orders' => Http::response([['order_id' => 'ORD-001']], 200),
        '*/iserver/account/orders' => Http::response([
            'orders' => [['orderId' => 'ORD-001', 'status' => 'Filled', 'avgPrice' => '115.00']],
        ], 200),
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
        'is_active' => true,
        'cooldown_minutes' => 1,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '115.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();
    expect(Order::count())->toBe(1);

    app(SyncOrderStatusAction::class)->handle();
    expect((float) $position->fresh()->quantity)->toBe(0.0);

    // Well past the cooldown, and the take-profit threshold is still crossed.
    $this->travel(10)->minutes();
    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('skips a position that still has an order in flight', function (): void {
    $position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '265598',
    ]);

    placedSellOrder($position);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 1,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '115.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});
