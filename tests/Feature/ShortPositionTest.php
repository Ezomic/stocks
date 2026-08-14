<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\ImportPositionsFromIbkrAction;
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
    Http::fake(['*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $this->user = User::factory()->create();
});

function shortPosition(string $quantity = '-10'): Position
{
    return Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => $quantity,
        'currency' => 'USD',
        'ibkr_con_id' => '265598',
    ]);
}

function priceNow(string $price): void
{
    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => $price,
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
}

it('recognises a negative quantity as a short', function (): void {
    expect(shortPosition()->isShort())->toBeTrue()
        ->and(Position::factory()->create(['quantity' => '10'])->isShort())->toBeFalse();
});

it('reports a falling price as a gain on a short', function (): void {
    // Sold at 100, now 75. That is a 25% gain, not a 25% loss.
    expect(shortPosition()->gainPct(75.0))->toBe(25.0);
});

it('reports a rising price as a loss on a short', function (): void {
    expect(shortPosition()->gainPct(125.0))->toBe(-25.0);
});

it('leaves a long position measured the way it always was', function (): void {
    $long = Position::factory()->create(['avg_buy_price' => '100.00', 'quantity' => '10']);

    expect($long->gainPct(125.0))->toBe(25.0)
        ->and($long->gainPct(75.0))->toBe(-25.0);
});

it('never sells a short', function (): void {
    shortPosition();

    Rule::factory()->create([
        'position_id' => Position::sole()->id,
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    priceNow('75.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('never buys back a short either', function (): void {
    // A buy rule would otherwise fire on a short, since the sell path is the only one that
    // checked the quantity.
    shortPosition();

    Rule::factory()->create([
        'position_id' => Position::sole()->id,
        'take_profit_pct' => null,
        'stop_loss_pct' => null,
        'buy_below_pct' => '10.00',
        'buy_amount' => '1000.00',
        'max_position_value' => '5000.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    priceNow('75.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('does not stamp a cooldown on a position it declined to trade', function (): void {
    shortPosition();

    Rule::factory()->create([
        'position_id' => Position::sole()->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    priceNow('75.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Position::sole()->last_triggered_at)->toBeNull();
});

it('still trades the long positions alongside it', function (): void {
    shortPosition();

    $long = Position::factory()->create([
        'symbol' => 'TSLA',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '76792991',
    ]);

    Rule::factory()->create([
        'position_id' => $long->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    priceNow('75.00');
    PriceSnapshot::create([
        'symbol' => 'TSLA', 'price' => '150.00', 'currency' => 'USD',
        'source' => 'ibkr', 'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::sole()->symbol)->toBe('TSLA');
});

it('stores a short the broker reports rather than dropping it', function (): void {
    Http::fake(['*/portfolio/*/positions/*' => Http::response([
        ['acctId' => 'DU0000001', 'conid' => 265598, 'ticker' => 'AAPL', 'position' => -5.0, 'avgCost' => 100.0, 'currency' => 'USD'],
    ], 200)]);

    app(ImportPositionsFromIbkrAction::class)->handle();

    expect((float) Position::sole()->quantity)->toBe(-5.0)
        ->and(Position::sole()->isShort())->toBeTrue();
});

it('labels a short as not traded wherever positions are listed', function (): void {
    shortPosition();
    priceNow('75.00');

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('short')
        ->assertSee('Short, not traded');
});
