<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\PlaceOrderAction;
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

    $this->user = User::factory()->create();
    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '265598',
    ]);
});

it('keeps filled orders when the position is deleted', function (): void {
    Order::factory()->create([
        'position_id' => $this->position->id,
        'symbol' => 'AAPL',
        'status' => 'filled',
        'fill_price' => '115.00',
    ]);

    $this->actingAs($this->user)
        ->delete("/positions/{$this->position->id}")
        ->assertRedirect('/positions');

    $order = Order::sole();

    expect(Position::count())->toBe(0)
        ->and($order->status)->toBe('filled')
        ->and($order->position_id)->toBeNull()
        ->and($order->symbol)->toBe('AAPL');
});

it('still shows an orphaned order in the order list', function (): void {
    Order::factory()->create([
        'position_id' => $this->position->id,
        'symbol' => 'AAPL',
        'status' => 'filled',
    ]);

    $this->actingAs($this->user)->delete("/positions/{$this->position->id}");

    $this->get('/orders')
        ->assertOk()
        ->assertSee('AAPL')
        ->assertSee('deleted');
});

it('records the symbol on every order it places', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect($order->symbol)->toBe('AAPL');
});

it('keeps evaluating rules when an orphaned order is still open', function (): void {
    // A NULL in a NOT IN list makes the whole comparison match nothing, which would have
    // silently stopped the engine for every position.
    $orphan = Order::factory()->create([
        'position_id' => $this->position->id,
        'symbol' => 'GONE',
        'status' => 'placed',
        'broker_order_id' => 'ORD-OLD',
    ]);

    $other = Position::factory()->create([
        'symbol' => 'TSLA',
        'avg_buy_price' => '100.00',
        'quantity' => '5',
        'ibkr_con_id' => '76792991',
    ]);

    $this->actingAs($this->user)->delete("/positions/{$this->position->id}");

    expect($orphan->fresh()->position_id)->toBeNull();

    Rule::factory()->create([
        'position_id' => $other->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'TSLA',
        'price' => '150.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced('ORD-NEW'), 200)]);
    app(EvaluateRulesAction::class)->handle();

    expect(Order::where('broker_order_id', 'ORD-NEW')->count())->toBe(1);
});

it('warns that history is kept before deleting', function (): void {
    $this->actingAs($this->user)
        ->get('/positions')
        ->assertOk()
        ->assertSee('order history is kept', false);
});
