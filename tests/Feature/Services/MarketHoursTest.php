<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\User;
use App\Notifications\ThresholdCrossed;
use App\Services\MarketHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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

function fakeTradingHours(string $hours, string $timezone = 'America/New_York'): void
{
    Http::fake([
        '*/iserver/contract/*/info' => Http::response([
            'con_id' => 265598,
            'symbol' => 'AAPL',
            'trading_hours' => $hours,
            'time_zone_id' => $timezone,
        ], 200),
    ]);
}

function atNewYork(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'America/New_York');
}

it('reports the market open during a session', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600');

    expect(app(MarketHours::class)->isOpen($this->position, atNewYork('2026-08-10 11:00')))->toBeTrue();
});

it('reports the market closed outside a session', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600');

    expect(app(MarketHours::class)->isOpen($this->position, atNewYork('2026-08-10 03:00')))->toBeFalse();
});

it('honours a holiday the broker reports as closed', function (): void {
    fakeTradingHours('20260810:CLOSED');

    expect(app(MarketHours::class)->isOpen($this->position, atNewYork('2026-08-10 11:00')))->toBeFalse();
});

it('honours a half day', function (): void {
    fakeTradingHours('20260810:0930-20260810:1300');

    $hours = app(MarketHours::class);

    expect($hours->isOpen($this->position, atNewYork('2026-08-10 12:00')))->toBeTrue()
        ->and($hours->isOpen($this->position, atNewYork('2026-08-10 14:00')))->toBeFalse();
});

it('handles several sessions in one day', function (): void {
    fakeTradingHours('20260810:0400-0930,0930-1600');

    $hours = app(MarketHours::class);

    expect($hours->isOpen($this->position, atNewYork('2026-08-10 05:00')))->toBeTrue()
        ->and($hours->isOpen($this->position, atNewYork('2026-08-10 15:00')))->toBeTrue()
        ->and($hours->isOpen($this->position, atNewYork('2026-08-10 20:00')))->toBeFalse();
});

it('handles a session running past midnight', function (): void {
    fakeTradingHours('20260810:1800-0200');

    expect(app(MarketHours::class)->isOpen($this->position, atNewYork('2026-08-10 23:00')))->toBeTrue();
});

it('treats crypto as always open without asking the broker', function (): void {
    $crypto = Position::factory()->create([
        'symbol' => 'BTC.USD',
        'market' => 'CRYPTO',
        'quantity' => '1',
        'ibkr_con_id' => '999',
    ]);

    expect(app(MarketHours::class)->isOpen($crypto, atNewYork('2026-08-10 03:00')))->toBeTrue();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/contract/'));
});

it('returns unknown rather than guessing when the broker will not say', function (): void {
    Http::fake(['*/iserver/contract/*/info' => Http::response(IbkrFakeResponses::authFailure(), 401)]);

    expect(app(MarketHours::class)->isOpen($this->position))->toBeNull();
});

it('returns unknown when the timezone is unusable', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600', 'Not/AZone');

    expect(app(MarketHours::class)->isOpen($this->position))->toBeNull();
});

it('asks the broker once and then uses the cached schedule', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600');

    $hours = app(MarketHours::class);
    $hours->isOpen($this->position, atNewYork('2026-08-10 11:00'));
    $hours->isOpen($this->position, atNewYork('2026-08-10 12:00'));
    $hours->isOpen($this->position, atNewYork('2026-08-10 13:00'));

    Http::assertSentCount(1);
});

it('does not place an order while the market is closed', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600');
    Http::fake(['*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL', 'price' => '150.00', 'currency' => 'USD',
        'source' => 'ibkr', 'fetched_at' => now(),
    ]);

    $this->travelTo(atNewYork('2026-08-10 03:00'));
    PriceSnapshot::query()->update(['fetched_at' => now()]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('places the order once the market is open', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600');
    Http::fake(['*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    $this->travelTo(atNewYork('2026-08-10 11:00'));

    PriceSnapshot::create([
        'symbol' => 'AAPL', 'price' => '150.00', 'currency' => 'USD',
        'source' => 'ibkr', 'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('still trades when the schedule is unknown, rather than freezing', function (): void {
    Http::fake([
        '*/iserver/contract/*/info' => Http::response(IbkrFakeResponses::authFailure(), 401),
        '*/iserver/account/*/orders' => Http::response(IbkrFakeResponses::orderPlaced(), 200),
    ]);

    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL', 'price' => '150.00', 'currency' => 'USD',
        'source' => 'ibkr', 'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('still alerts while the market is closed, since an alert places nothing', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600');

    $user = User::factory()->create();
    Notification::fake();

    Rule::factory()->create([
        'position_id' => $this->position->id,
        'action' => 'notify',
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    $this->travelTo(atNewYork('2026-08-10 03:00'));

    PriceSnapshot::create([
        'symbol' => 'AAPL', 'price' => '150.00', 'currency' => 'USD',
        'source' => 'ibkr', 'fetched_at' => now(),
    ]);

    app(EvaluateRulesAction::class)->handle();

    Notification::assertSentTo($user, ThresholdCrossed::class);
});

it('warns on the dashboard about a closed market', function (): void {
    fakeTradingHours('20260810:0930-20260810:1600');

    $this->travelTo(atNewYork('2026-08-10 03:00'));

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('market that is currently closed');
});
