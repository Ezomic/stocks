<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Actions\SyncOrderStatusAction;
use App\Actions\SyncPricesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

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

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '120.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
});

it('trades by default so an untouched install behaves as before', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('places no orders while trading is paused', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    Setting::setBool(Setting::TRADING_ENABLED, false);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('resumes trading when the switch is turned back on', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-001']], 200)]);

    Setting::setBool(Setting::TRADING_ENABLED, false);
    app(EvaluateRulesAction::class)->handle();

    Setting::setBool(Setting::TRADING_ENABLED, true);
    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('keeps syncing prices and order statuses while paused', function (): void {
    Setting::setBool(Setting::TRADING_ENABLED, false);

    Http::fake([
        '*/marketdata/snapshot*' => Http::response([['conid' => 265598, '31' => '130.00']], 200),
        '*/iserver/account/orders' => Http::response(['orders' => []], 200),
    ]);

    app(SyncPricesAction::class)->handle();
    app(SyncOrderStatusAction::class)->handle();

    expect(PriceSnapshot::where('price', '130.0000')->count())->toBe(1);
});

it('toggles the switch from the settings page', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/settings/trading', ['trading_enabled' => '0'])
        ->assertRedirect();

    expect(Setting::tradingEnabled())->toBeFalse();

    $this->post('/settings/trading', ['trading_enabled' => '1'])->assertRedirect();

    expect(Setting::tradingEnabled())->toBeTrue();
});

it('shows the paused state on the settings page', function (): void {
    Setting::setBool(Setting::TRADING_ENABLED, false);

    $this->actingAs(User::factory()->create())
        ->get('/settings')
        ->assertOk()
        ->assertSee('Resume trading')
        ->assertSee('No orders will be placed');
});

it('warns on the dashboard while trading is paused', function (): void {
    Setting::setBool(Setting::TRADING_ENABLED, false);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Automated trading is paused');
});

it('does not warn on the dashboard while trading is running', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertDontSee('Automated trading is paused');
});

it('requires authentication to toggle trading', function (): void {
    $this->post('/settings/trading', ['trading_enabled' => '0'])->assertRedirect('/login');

    expect(Setting::tradingEnabled())->toBeTrue();
});
