<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncOrderStatusAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '265598',
    ]);
});

function abandonedOrder(Position $position, int $placedMinutesAgo): Order
{
    return Order::factory()->create([
        'position_id' => $position->id,
        'side' => 'sell',
        'quantity' => '10',
        'status' => 'placed',
        'broker_order_id' => 'ORD-GONE',
        'placed_at' => now()->subMinutes($placedMinutesAgo),
    ]);
}

/** @param array<int, array<string, mixed>> $positions */
function fakeBrokerSilence(array $positions = []): void
{
    Http::fake([
        '*/portfolio/*/positions/*' => Http::response($positions, 200),
        '*/iserver/account/orders' => Http::response(['orders' => []], 200),
    ]);
}

it('leaves a recently placed order alone', function (): void {
    fakeBrokerSilence();
    $order = abandonedOrder($this->position, 5);

    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('placed');
});

it('marks an order the broker has forgotten as needing review', function (): void {
    fakeBrokerSilence([['conid' => 265598, 'position' => 10.0]]);
    $order = abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('unreconciled')
        ->and($order->fresh()->error_message)->toContain('most likely never filled');
});

it('corrects the local quantity from the broker figure when they disagree', function (): void {
    fakeBrokerSilence([['conid' => 265598, 'position' => 0.0]]);
    $order = abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    expect((float) $this->position->fresh()->quantity)->toBe(0.0)
        ->and($order->fresh()->error_message)->toContain('corrected from 10 to 0');
});

it('says so when the broker reports no position for the contract either', function (): void {
    fakeBrokerSilence([]);
    $order = abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('unreconciled')
        ->and($order->fresh()->error_message)->toContain('could not be confirmed')
        ->and((float) $this->position->fresh()->quantity)->toBe(10.0);
});

it('waits for another cycle when the broker positions call fails', function (): void {
    Http::fake([
        '*/portfolio/*/positions/*' => Http::response(IbkrFakeResponses::authFailure(), 401),
        '*/iserver/account/orders' => Http::response(['orders' => []], 200),
    ]);

    $order = abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('placed');
});

it('lets the position be traded again once the order is out of flight', function (): void {
    fakeBrokerSilence([['conid' => 265598, 'position' => 10.0]]);
    abandonedOrder($this->position, 45);

    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 1,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '120.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    // Before the fix this position was frozen for good.
    app(EvaluateRulesAction::class)->handle();
    expect(Order::where('status', 'placed')->count())->toBe(1);

    app(SyncOrderStatusAction::class)->handle();

    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced('ORD-NEW'), 200)]);
    app(EvaluateRulesAction::class)->handle();

    expect(Order::where('broker_order_id', 'ORD-NEW')->count())->toBe(1);
});

it('respects a configured reconciliation timeout', function (): void {
    config(['ibkr.order_reconcile_timeout_minutes' => 120]);

    fakeBrokerSilence([['conid' => 265598, 'position' => 10.0]]);
    $order = abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('placed');
});

it('still reconciles an order the broker does report', function (): void {
    Http::fake([
        '*/iserver/account/orders' => Http::response(
            IbkrFakeResponses::orderStatus('ORD-GONE', 'Filled', '115.00'),
            200
        ),
    ]);

    $order = abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('filled')
        ->and((float) $this->position->fresh()->quantity)->toBe(0.0);
});

it('warns on the dashboard while an order needs review', function (): void {
    fakeBrokerSilence([['conid' => 265598, 'position' => 10.0]]);
    abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('could not be reconciled');
});

it('labels an unreconciled order in the order list', function (): void {
    fakeBrokerSilence([['conid' => 265598, 'position' => 10.0]]);
    abandonedOrder($this->position, 45);

    app(SyncOrderStatusAction::class)->handle();

    $this->actingAs(User::factory()->create())
        ->get('/orders')
        ->assertOk()
        ->assertSee('needs review');
});
