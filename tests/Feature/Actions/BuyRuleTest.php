<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncOrderStatusAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();
    // Scoped to the placement endpoint so a test can still fake the order-status one:
    // Http::fake() merges stubs and the first matching pattern wins.
    Http::fake(['*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $this->user = User::factory()->create();
    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'currency' => 'USD',
        'market' => 'STK',
        'ibkr_con_id' => '265598',
    ]);
});

function buyRule(Position $position, array $overrides = []): Rule
{
    return Rule::factory()->create(array_merge([
        'position_id' => $position->id,
        'take_profit_pct' => null,
        'stop_loss_pct' => null,
        'buy_below_pct' => '20.00',
        'buy_amount' => '1000.00',
        'max_position_value' => '5000.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ], $overrides));
}

function marketPrice(string $price, string $symbol = 'AAPL'): void
{
    PriceSnapshot::create([
        'symbol' => $symbol,
        'price' => $price,
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
}

it('buys when the price falls far enough below the average paid', function (): void {
    buyRule($this->position);
    marketPrice('75.00');

    app(EvaluateRulesAction::class)->handle();

    $order = Order::sole();

    // 1000 of cash at 75 buys 13 whole shares.
    expect($order->side)->toBe('buy')
        ->and((float) $order->quantity)->toBe(13.0);
});

it('holds while the price is above the buy level', function (): void {
    buyRule($this->position);
    marketPrice('85.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('buys into a position that holds nothing at all', function (): void {
    $exited = Position::factory()->create([
        'symbol' => 'EXIT',
        'avg_buy_price' => '100.00',
        'quantity' => '0',
        'currency' => 'USD',
        'ibkr_con_id' => '111',
    ]);

    buyRule($exited, ['position_id' => $exited->id]);
    marketPrice('75.00', 'EXIT');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::sole()->side)->toBe('buy');
});

it('never lets a position exceed its value cap', function (): void {
    // 10 held at 400 is already 4000 against a 5000 cap, so only 1000 of headroom remains.
    $this->position->update(['avg_buy_price' => '500.00']);
    buyRule($this->position, ['max_position_value' => '5000.00', 'buy_amount' => '9000.00']);
    marketPrice('400.00');

    app(EvaluateRulesAction::class)->handle();

    expect((float) Order::sole()->quantity)->toBe(2.0);
});

it('reports rather than buying when the cap is already reached', function (): void {
    $this->position->update(['avg_buy_price' => '500.00', 'quantity' => '20']);
    buyRule($this->position, ['max_position_value' => '5000.00']);
    marketPrice('400.00');

    app(EvaluateRulesAction::class)->handle();

    $order = Order::sole();

    expect($order->status)->toBe('failed')
        ->and($order->error_message)->toContain('no room left under its position value cap');
});

it('sizes a crypto buy in fractions', function (): void {
    $crypto = Position::factory()->create([
        'symbol' => 'BTC.USD',
        'market' => 'CRYPTO',
        'avg_buy_price' => '40000',
        'quantity' => '1',
        'currency' => 'USD',
        'ibkr_con_id' => '222',
    ]);

    $rule = buyRule($crypto, ['position_id' => $crypto->id, 'buy_amount' => '1000', 'max_position_value' => '100000']);

    expect($rule->buyQuantity($crypto, 20000.0))->toBe(0.05);
});

it('prefers selling when a sell and a buy would both trigger', function (): void {
    // Getting out of something is never made safer by adding to it first.
    buyRule($this->position, ['stop_loss_pct' => '10.00', 'buy_below_pct' => '20.00']);
    marketPrice('75.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::sole()->side)->toBe('sell');
});

it('moves the average paid when a buy fills', function (): void {
    Order::factory()->create([
        'position_id' => $this->position->id,
        'symbol' => 'AAPL',
        'side' => 'buy',
        'quantity' => '10',
        'status' => 'placed',
        'broker_order_id' => 'ORD-1',
    ]);

    Http::fake(['*/iserver/account/orders' => Http::response(IbkrFakeResponses::orderStatus('ORD-1', 'Filled', '50.00', 10.0), 200)]);

    app(SyncOrderStatusAction::class)->handle();

    $position = $this->position->fresh();

    // 10 at 100 plus 10 at 50 averages 75.
    expect((float) $position->quantity)->toBe(20.0)
        ->and((float) $position->avg_buy_price)->toBe(75.0);
});

it('leaves the average alone when a sell fills', function (): void {
    Order::factory()->create([
        'position_id' => $this->position->id,
        'symbol' => 'AAPL',
        'side' => 'sell',
        'quantity' => '5',
        'status' => 'placed',
        'broker_order_id' => 'ORD-1',
    ]);

    Http::fake(['*/iserver/account/orders' => Http::response(IbkrFakeResponses::orderStatus('ORD-1', 'Filled', '200.00', 5.0), 200)]);

    app(SyncOrderStatusAction::class)->handle();

    expect((float) $this->position->fresh()->avg_buy_price)->toBe(100.0);
});

it('is stopped by the kill switch', function (): void {
    Setting::setBool(Setting::TRADING_ENABLED, false);

    buyRule($this->position);
    marketPrice('75.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('is simulated rather than sent during a dry run', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    buyRule($this->position);
    marketPrice('75.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::sole()->status)->toBe('simulated')
        ->and(Order::sole()->side)->toBe('buy');
});

it('respects the cooldown between buys', function (): void {
    buyRule($this->position);
    marketPrice('75.00');

    app(EvaluateRulesAction::class)->handle();

    $this->travel(10)->minutes();
    marketPrice('75.00');
    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('refuses a buy rule with no amount to spend', function (): void {
    $this->actingAs($this->user)->post('/rules', [
        'position_id' => (string) $this->position->id,
        'buy_below_pct' => '20',
        'max_position_value' => '5000',
        'cooldown_minutes' => '60',
    ])->assertSessionHasErrors('buy_amount');
});

it('refuses an uncapped buy rule', function (): void {
    $this->actingAs($this->user)->post('/rules', [
        'position_id' => (string) $this->position->id,
        'buy_below_pct' => '20',
        'buy_amount' => '1000',
        'cooldown_minutes' => '60',
    ])->assertSessionHasErrors('max_position_value');
});

it('saves a complete buy rule from the form', function (): void {
    $this->actingAs($this->user)->post('/rules', [
        'position_id' => (string) $this->position->id,
        'buy_below_pct' => '20',
        'buy_amount' => '1000',
        'max_position_value' => '5000',
        'cooldown_minutes' => '60',
        'is_active' => '1',
    ])->assertSessionHasNoErrors();

    expect(Rule::sole()->buys())->toBeTrue();
});

it('leaves existing sell-only rules alone', function (): void {
    $rule = Rule::factory()->create(['position_id' => $this->position->id]);

    expect($rule->buys())->toBeFalse();
});
