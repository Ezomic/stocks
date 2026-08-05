<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '265598',
    ]);

    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);
});

function snapshotAgedMinutes(int $minutes): void
{
    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '115.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now()->subMinutes($minutes),
    ]);
}

it('does not trade on a price older than the configured max age', function (): void {
    fakeIbkrAuth();
    Http::fake(['*' => Http::response([['order_id' => 'ORD-STALE']], 200)]);

    snapshotAgedMinutes(30);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('trades on a price inside the max age', function (): void {
    fakeIbkrAuth();
    Http::fake(['*' => Http::response([['order_id' => 'ORD-FRESH']], 200)]);

    snapshotAgedMinutes(1);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('respects a configured max price age', function (): void {
    config(['ibkr.max_price_age_minutes' => 60]);

    fakeIbkrAuth();
    Http::fake(['*' => Http::response([['order_id' => 'ORD-WIDE']], 200)]);

    snapshotAgedMinutes(30);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('does not evaluate any rule while the gateway session is down', function (): void {
    fakeIbkrAuth(false);
    Http::fake(['*' => Http::response([['order_id' => 'ORD-NOSESSION']], 200)]);

    snapshotAgedMinutes(0);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('does not evaluate any rule when the gateway is unreachable', function (): void {
    Http::fake(['*' => fn () => throw new ConnectionException('gateway down')]);

    snapshotAgedMinutes(0);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('warns on the dashboard when prices have gone stale', function (): void {
    fakeIbkrAuth();
    snapshotAgedMinutes(30);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('no price newer than', false)
        ->assertSee('stale', false);
});

it('does not warn on the dashboard while prices are fresh', function (): void {
    fakeIbkrAuth();
    snapshotAgedMinutes(1);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertDontSee('no price newer than', false);
});
