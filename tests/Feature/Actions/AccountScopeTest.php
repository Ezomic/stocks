<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncPricesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function triggeringSnapshot(string $symbol): void
{
    PriceSnapshot::create([
        'symbol' => $symbol,
        'price' => '150.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
}

it('does not evaluate rules for a position belonging to another account mode', function (): void {
    $position = Position::factory()->create([
        'symbol' => 'LIVE',
        'account_mode' => 'live',
        'broker_account_id' => 'U0000001',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '111',
    ]);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    triggeringSnapshot('LIVE');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('does not evaluate rules for a position belonging to another broker account', function (): void {
    $position = Position::factory()->create([
        'symbol' => 'OTHER',
        'broker_account_id' => 'DU9999999',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '222',
    ]);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    triggeringSnapshot('OTHER');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('evaluates rules for a position on the active account', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-ACTIVE']], 200)]);

    $position = Position::factory()->create([
        'symbol' => 'ACTIVE',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '333',
    ]);

    Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    triggeringSnapshot('ACTIVE');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('does not sync prices for positions outside the active account', function (): void {
    Http::fake([
        '*' => Http::response([['conid' => 444, '31' => '150.00'], ['conid' => 555, '31' => '160.00']], 200),
    ]);

    Position::factory()->create([
        'symbol' => 'MINE',
        'ibkr_con_id' => '444',
    ]);

    Position::factory()->create([
        'symbol' => 'THEIRS',
        'account_mode' => 'live',
        'broker_account_id' => 'U0000001',
        'ibkr_con_id' => '555',
    ]);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::where('symbol', 'MINE')->count())->toBe(1)
        ->and(PriceSnapshot::where('symbol', 'THEIRS')->count())->toBe(0);
});

it('warns on the dashboard when positions belong to another account', function (): void {
    Http::fake(['*' => Http::response(['authenticated' => true], 200)]);

    Position::factory()->create(['symbol' => 'MINE']);
    Position::factory()->create([
        'symbol' => 'THEIRS',
        'account_mode' => 'live',
        'broker_account_id' => 'U0000001',
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('belongs to another broker account', false);
});
