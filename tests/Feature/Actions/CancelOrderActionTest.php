<?php

declare(strict_types=1);

use App\Actions\SyncOrderStatusAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OrderStatusChanged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10']);
    $this->user = User::factory()->create();
});

function livePlacedOrder(Position $position): Order
{
    return Order::factory()->create([
        'position_id' => $position->id,
        'side' => 'sell',
        'quantity' => '10',
        'status' => 'placed',
        'broker_order_id' => 'ORD-001',
        'placed_at' => now(),
    ]);
}

it('sends the cancellation to IBKR and records that it is in flight', function (): void {
    Http::fake(['*' => Http::response(['order_id' => 'ORD-001', 'msg' => 'Request was submitted'], 200)]);

    $order = livePlacedOrder($this->position);

    $this->actingAs($this->user)
        ->post(route('orders.cancel', $order))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($order->fresh()->cancel_requested_at)->not->toBeNull()
        ->and($order->fresh()->status)->toBe('placed');

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
        && str_contains($request->url(), '/order/ORD-001'));
});

it('does not claim the order is cancelled before the broker says so', function (): void {
    Http::fake([
        '*/iserver/account/*/order/*' => Http::response(['order_id' => 'ORD-001', 'msg' => 'Request was submitted'], 200),
        '*/iserver/account/orders' => Http::response(IbkrFakeResponses::orderStatus('ORD-001', 'Cancelled'), 200),
    ]);

    $order = livePlacedOrder($this->position);

    $this->actingAs($this->user)->post(route('orders.cancel', $order));

    expect($order->fresh()->status)->toBe('placed');

    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('cancelled');
});

it('reports a refused cancellation instead of silently succeeding', function (): void {
    Http::fake(['*' => Http::response(['error' => 'no such order'], 400)]);

    $order = livePlacedOrder($this->position);

    $this->actingAs($this->user)
        ->post(route('orders.cancel', $order))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($order->fresh()->cancel_requested_at)->toBeNull();
});

it('refuses to cancel an order that already filled', function (): void {
    $order = Order::factory()->create([
        'position_id' => $this->position->id,
        'status' => 'filled',
        'broker_order_id' => 'ORD-001',
    ]);

    $this->actingAs($this->user)
        ->post(route('orders.cancel', $order))
        ->assertSessionHas('error');

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
});

it('refuses to cancel a simulated order', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    $order = Order::factory()->create([
        'position_id' => $this->position->id,
        'status' => 'simulated',
    ]);

    $this->actingAs($this->user)
        ->post(route('orders.cancel', $order))
        ->assertSessionHas('error');

    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
});

it('notifies when the broker confirms a cancellation', function (): void {
    Notification::fake();

    $order = livePlacedOrder($this->position);

    Http::fake(['*' => Http::response(IbkrFakeResponses::orderStatus('ORD-001', 'Cancelled'), 200)]);
    app(SyncOrderStatusAction::class)->handle();

    expect($order->fresh()->status)->toBe('cancelled');

    Notification::assertSentTo(
        $this->user,
        OrderStatusChanged::class,
        fn (OrderStatusChanged $notification): bool => $notification->event === 'cancelled'
    );
});

it('offers a cancel button only while the order is live', function (): void {
    livePlacedOrder($this->position);

    $this->actingAs($this->user)->get('/orders')->assertOk()->assertSee('Cancel');

    Order::query()->update(['status' => 'filled']);

    $this->get('/orders')->assertOk()->assertDontSee('>Cancel<', false);
});

it('shows a cancelling badge once the request is in flight', function (): void {
    $order = livePlacedOrder($this->position);
    $order->update(['cancel_requested_at' => now()]);

    $this->actingAs($this->user)->get('/orders')->assertOk()->assertSee('cancelling');
});

it('keeps cancellation behind authentication', function (): void {
    $order = livePlacedOrder($this->position);

    $this->post(route('orders.cancel', $order))->assertRedirect('/login');

    expect($order->fresh()->cancel_requested_at)->toBeNull();
});
