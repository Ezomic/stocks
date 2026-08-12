<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ThresholdCrossed;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);
    Notification::fake();

    $this->user = User::factory()->create();
    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'ibkr_con_id' => '265598',
    ]);
});

function alertRule(Position $position, array $overrides = []): Rule
{
    return Rule::factory()->create(array_merge([
        'position_id' => $position->id,
        'action' => 'notify',
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ], $overrides));
}

function priceOf(string $price): void
{
    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => $price,
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
}

it('notifies instead of placing an order', function (): void {
    alertRule($this->position);
    priceOf('150.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);

    Notification::assertSentTo(
        $this->user,
        ThresholdCrossed::class,
        fn (ThresholdCrossed $n): bool => $n->threshold === 'take_profit' && $n->price === 150.0
    );
});

it('sends nothing to the gateway', function (): void {
    alertRule($this->position);
    priceOf('150.00');

    app(EvaluateRulesAction::class)->handle();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/iserver/account/'));
});

it('reports which threshold was crossed', function (): void {
    alertRule($this->position);
    priceOf('80.00');

    app(EvaluateRulesAction::class)->handle();

    Notification::assertSentTo(
        $this->user,
        ThresholdCrossed::class,
        fn (ThresholdCrossed $n): bool => $n->threshold === 'stop_loss'
    );
});

it('stays quiet inside the thresholds', function (): void {
    alertRule($this->position);
    priceOf('105.00');

    app(EvaluateRulesAction::class)->handle();

    Notification::assertNothingSent();
});

it('shares the cooldown with trading rules so it cannot nag every minute', function (): void {
    alertRule($this->position);
    priceOf('150.00');

    app(EvaluateRulesAction::class)->handle();

    $this->travel(10)->minutes();
    priceOf('151.00');
    app(EvaluateRulesAction::class)->handle();

    Notification::assertSentToTimes($this->user, ThresholdCrossed::class, 1);
});

it('alerts again once the cooldown has passed', function (): void {
    alertRule($this->position);
    priceOf('150.00');

    app(EvaluateRulesAction::class)->handle();

    $this->travel(2)->hours();
    priceOf('151.00');
    app(EvaluateRulesAction::class)->handle();

    Notification::assertSentToTimes($this->user, ThresholdCrossed::class, 2);
});

it('agrees with a trading rule about when a level is crossed', function (): void {
    // Same thresholds, one alerting and one trading, must fire on the same price.
    $alerting = Position::factory()->create([
        'symbol' => 'ALRT', 'avg_buy_price' => '100.00', 'quantity' => '10', 'ibkr_con_id' => '1',
    ]);
    $trading = Position::factory()->create([
        'symbol' => 'TRAD', 'avg_buy_price' => '100.00', 'quantity' => '10', 'ibkr_con_id' => '2',
    ]);

    alertRule($alerting, ['position_id' => $alerting->id]);
    Rule::factory()->create([
        'position_id' => $trading->id, 'action' => 'order', 'take_profit_pct' => '10.00',
        'stop_loss_pct' => '10.00', 'is_active' => true, 'cooldown_minutes' => 60,
    ]);

    foreach (['ALRT', 'TRAD'] as $symbol) {
        PriceSnapshot::create([
            'symbol' => $symbol, 'price' => '110.00', 'currency' => 'USD',
            'source' => 'ibkr', 'fetched_at' => now(),
        ]);
    }

    app(EvaluateRulesAction::class)->handle();

    expect(Order::where('symbol', 'TRAD')->count())->toBe(1)
        ->and(Order::where('symbol', 'ALRT')->count())->toBe(0);

    Notification::assertSentToTimes($this->user, ThresholdCrossed::class, 1);
});

it('is silenced by the kill switch like everything else', function (): void {
    Setting::setBool(Setting::TRADING_ENABLED, false);

    alertRule($this->position);
    priceOf('150.00');

    app(EvaluateRulesAction::class)->handle();

    Notification::assertNothingSent();
});

it('saves the action from the rule form', function (): void {
    $this->actingAs($this->user)
        ->post('/rules', [
            'position_id' => (string) $this->position->id,
            'action' => 'notify',
            'take_profit_pct' => '10',
            'cooldown_minutes' => '60',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect(Rule::sole()->alertsOnly())->toBeTrue();
});

it('rejects an unknown action', function (): void {
    $this->actingAs($this->user)
        ->post('/rules', [
            'position_id' => (string) $this->position->id,
            'take_profit_pct' => '10',
            'action' => 'nonsense',
            'cooldown_minutes' => '60',
        ])
        ->assertSessionHasErrors('action');
});

it('defaults existing rules to trading', function (): void {
    expect(Rule::factory()->create(['position_id' => $this->position->id])->alertsOnly())->toBeFalse();
});

it('marks an alert rule as such in the interface', function (): void {
    alertRule($this->position);
    priceOf('105.00');

    $this->actingAs($this->user)->get('/')->assertOk()->assertSee('Alert:');
});
