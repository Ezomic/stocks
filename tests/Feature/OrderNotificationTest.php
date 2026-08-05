<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\PlaceOrderAction;
use App\Actions\SyncOrderStatusAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OrderStatusChanged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();
    Notification::fake();

    $this->admin = User::factory()->create();

    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'currency' => 'USD',
        'ibkr_con_id' => '265598',
    ]);
});

it('notifies when an order is placed', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    app(PlaceOrderAction::class)->handle($this->position, 'sell');

    Notification::assertSentTo(
        $this->admin,
        OrderStatusChanged::class,
        fn (OrderStatusChanged $notification): bool => $notification->event === 'placed'
    );
});

it('notifies when an order fails', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 401)]);

    app(PlaceOrderAction::class)->handle($this->position, 'sell');

    Notification::assertSentTo(
        $this->admin,
        OrderStatusChanged::class,
        fn (OrderStatusChanged $notification): bool => $notification->event === 'failed'
    );
});

it('notifies when an order fills', function (): void {
    Order::factory()->create([
        'position_id' => $this->position->id,
        'side' => 'sell',
        'quantity' => '10',
        'status' => 'placed',
        'broker_order_id' => 'ORD-001',
    ]);

    Http::fake(['*' => Http::response([
        'orders' => [['orderId' => 'ORD-001', 'status' => 'Filled', 'avgPrice' => '115.00']],
    ], 200)]);

    app(SyncOrderStatusAction::class)->handle();

    Notification::assertSentTo(
        $this->admin,
        OrderStatusChanged::class,
        fn (OrderStatusChanged $notification): bool => $notification->event === 'filled'
    );
});

it('does not notify for a simulated order', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    app(PlaceOrderAction::class)->handle($this->position, 'sell');

    Notification::assertNothingSent();
});

it('sends nothing when notifications are disabled', function (): void {
    config(['notifications.enabled' => false]);

    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    app(PlaceOrderAction::class)->handle($this->position, 'sell');

    Notification::assertNothingSent();
});

it('sends nothing when no channel is configured', function (): void {
    config(['notifications.channels' => []]);

    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    app(PlaceOrderAction::class)->handle($this->position, 'sell');

    Notification::assertNothingSent();
});

it('honours the configured event list', function (): void {
    config(['notifications.events' => ['filled']]);

    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    app(PlaceOrderAction::class)->handle($this->position, 'sell');

    Notification::assertNothingSent();
});

it('describes the symbol, side, quantity, price and triggering rule', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '118.40',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    $rule = Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(PlaceOrderAction::class)->handle($this->position, 'sell', $rule);

    Notification::assertSentTo($this->admin, OrderStatusChanged::class, function (OrderStatusChanged $notification): bool {
        $mail = $notification->toMail($this->admin);
        $body = $mail->subject.' '.implode(' ', $mail->introLines);

        return str_contains($body, 'AAPL')
            && str_contains($body, 'SELL')
            && str_contains($body, '10')
            && str_contains($body, '118.40')
            && str_contains($body, 'position rule')
            && str_contains($body, 'take profit 10.00%');
    });
});

it('reports the fill price once an order has filled', function (): void {
    $order = Order::factory()->create([
        'position_id' => $this->position->id,
        'side' => 'sell',
        'quantity' => '10',
        'status' => 'filled',
        'fill_price' => '115.00',
    ]);

    $mail = (new OrderStatusChanged($order, 'filled'))->toMail($this->admin);

    expect(implode(' ', $mail->introLines))->toContain('USD 115.0000 (filled)');
});

it('keeps the run going when notification dispatch throws', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    Notification::shouldReceive('send')->andThrow(new RuntimeException('mail server down'));

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('placed');
});

it('does not notify while rules are simply being evaluated without a trigger', function (): void {
    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '50.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '101.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    Notification::assertNothingSent();
});
