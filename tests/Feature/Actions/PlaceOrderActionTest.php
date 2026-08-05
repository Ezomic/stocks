<?php

declare(strict_types=1);

use App\Actions\PlaceOrderAction;
use App\Models\Position;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'quantity' => '10',
        'avg_buy_price' => '100.00',
        'ibkr_con_id' => '265598',
    ]);
});

it('records a placed order when IBKR returns an order id', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced('ORD-001'), 200)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('placed')
        ->and($order->broker_order_id)->toBe('ORD-001')
        ->and($order->placed_at)->not->toBeNull()
        ->and($order->error_message)->toBeNull();
});

it('answers the confirmation challenge and records the resulting order id', function (): void {
    Http::fake([
        '*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderConfirmationChallenge('REPLY-1'), 200),
        '*/iserver/reply/*' => Http::response(IbkrFakeResponses::orderPlaced('ORD-002'), 200),
    ]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('placed')
        ->and($order->broker_order_id)->toBe('ORD-002');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/iserver/reply/REPLY-1'));
});

it('fails the order when the gateway session has expired', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::authFailure(), 401)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('failed')
        ->and($order->broker_order_id)->toBeNull()
        ->and($order->placed_at)->toBeNull()
        ->and($order->error_message)->toContain('HTTP 401');
});

it('fails the order when the gateway returns a server error', function (): void {
    Http::fake(['*' => Http::response('gateway exploded', 500)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('failed')
        ->and($order->error_message)->toContain('HTTP 500');
});

it('fails the order when IBKR returns success but no order id', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('failed')
        ->and($order->broker_order_id)->toBeNull()
        ->and($order->error_message)->toContain('no order id');
});

it('fails the order when the confirmation challenge carries no reply id', function (): void {
    Http::fake(['*' => Http::response([['messageIds' => ['o163']]], 200)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('failed')
        ->and($order->error_message)->toContain('without a reply id');
});

it('fails the order when the confirmation reply itself is rejected', function (): void {
    Http::fake([
        '*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderConfirmationChallenge('REPLY-2'), 200),
        '*/iserver/reply/*' => Http::response(['error' => 'rejected'], 400),
    ]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->status)->toBe('failed')
        ->and($order->error_message)->toContain('HTTP 400');
});

it('always leaves an order record behind for the audit log', function (): void {
    Http::fake(['*' => Http::response(['error' => 'nope'], 503)]);

    app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($this->position->orders()->count())->toBe(1);
});
