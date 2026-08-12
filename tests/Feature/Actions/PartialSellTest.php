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
        'market' => 'STK',
        'ibkr_con_id' => '265598',
    ]);
});

function ladderRule(Position $position, string $sellPct): Rule
{
    return Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => null,
        'sell_pct' => $sellPct,
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);
}

function winningPrice(): void
{
    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '150.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
}

it('sells only the requested share of the position', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    ladderRule($this->position, '50.00');
    winningPrice();

    app(EvaluateRulesAction::class)->handle();

    expect((float) Order::sole()->quantity)->toBe(5.0);
});

it('still sells everything by default', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    ladderRule($this->position, '100.00');
    winningPrice();

    app(EvaluateRulesAction::class)->handle();

    expect((float) Order::sole()->quantity)->toBe(10.0);
});

it('sends whole units for an equity', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $rule = ladderRule($this->position, '33.00');

    expect($rule->sellQuantity($this->position))->toBe(3.0);
});

it('allows fractions for crypto', function (): void {
    $crypto = Position::factory()->create([
        'symbol' => 'BTC.USD',
        'market' => 'CRYPTO',
        'quantity' => '0.5',
        'avg_buy_price' => '30000',
    ]);

    $rule = ladderRule($crypto, '50.00');

    expect($rule->sellQuantity($crypto))->toBe(0.25);
});

it('never asks for more than is held', function (): void {
    $rule = ladderRule($this->position, '100.00');

    expect($rule->sellQuantity($this->position))->toBe(10.0);
});

it('reports a step too small to express instead of doing nothing quietly', function (): void {
    $small = Position::factory()->create([
        'symbol' => 'TINY',
        'avg_buy_price' => '100.00',
        'quantity' => '3',
        'market' => 'STK',
        'ibkr_con_id' => '111',
    ]);

    Rule::factory()->create([
        'position_id' => $small->id,
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => null,
        'sell_pct' => '20.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'TINY',
        'price' => '150.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    $order = Order::sole();

    expect($order->status)->toBe('failed')
        ->and($order->error_message)->toContain('rounds to less than one unit');

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/iserver/account/'));
});

it('works down the position one step per cooldown', function (): void {
    Http::fake([
        '*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderPlaced('ORD-1'), 200),
        '*/iserver/account/orders' => Http::response(IbkrFakeResponses::orderStatus('ORD-1', 'Filled', '150.00', 5.0), 200),
    ]);

    ladderRule($this->position, '50.00');
    winningPrice();

    app(EvaluateRulesAction::class)->handle();
    expect((float) Order::sole()->quantity)->toBe(5.0);

    app(SyncOrderStatusAction::class)->handle();
    expect((float) $this->position->fresh()->quantity)->toBe(5.0);

    // Inside the cooldown nothing more goes out.
    $this->travel(10)->minutes();
    PriceSnapshot::create([
        'symbol' => 'AAPL', 'price' => '150.00', 'currency' => 'USD', 'source' => 'ibkr', 'fetched_at' => now(),
    ]);
    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('records what was left after the fill', function (): void {
    Http::fake([
        '*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderPlaced('ORD-1'), 200),
        '*/iserver/account/orders' => Http::response(IbkrFakeResponses::orderStatus('ORD-1', 'Filled', '150.00', 4.0), 200),
    ]);

    ladderRule($this->position, '40.00');
    winningPrice();

    app(EvaluateRulesAction::class)->handle();
    app(SyncOrderStatusAction::class)->handle();

    expect((float) Order::sole()->remaining_quantity)->toBe(6.0);
});

it('shows the remaining quantity in the order list', function (): void {
    Order::factory()->create([
        'position_id' => $this->position->id,
        'symbol' => 'AAPL',
        'quantity' => '4',
        'remaining_quantity' => '6',
        'status' => 'filled',
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/orders')
        ->assertOk()
        ->assertSee('Remaining');
});

it('saves the sell size from the rule form', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/rules', [
            'position_id' => (string) $this->position->id,
            'take_profit_pct' => '10',
            'sell_pct' => '25',
            'cooldown_minutes' => '60',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect((float) Rule::sole()->sell_pct)->toBe(25.0)
        ->and(Rule::sole()->isPartial())->toBeTrue();
});

it('rejects a sell size outside one to a hundred percent', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/rules', [
        'position_id' => (string) $this->position->id,
        'take_profit_pct' => '10',
        'sell_pct' => '150',
        'cooldown_minutes' => '60',
    ])->assertSessionHasErrors('sell_pct');
});

it('defaults existing rules to selling everything', function (): void {
    $rule = Rule::factory()->create(['position_id' => $this->position->id]);

    expect((float) $rule->sell_pct)->toBe(100.0)
        ->and($rule->isPartial())->toBeFalse();
});
