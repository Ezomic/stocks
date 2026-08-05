<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
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

it('records a simulated order and sends nothing to IBKR', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    app(EvaluateRulesAction::class)->handle();

    $order = Order::sole();

    expect($order->status)->toBe('simulated')
        ->and($order->side)->toBe('sell')
        ->and((float) $order->quantity)->toBe(10.0)
        ->and($order->rule_id)->not->toBeNull()
        ->and($order->broker_order_id)->toBeNull()
        ->and($order->placed_at)->toBeNull();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/orders'));
});

it('places a real order once dry run is turned off', function (): void {
    Http::fake(['*' => Http::response([['order_id' => 'ORD-REAL']], 200)]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::sole()->status)->toBe('placed');
});

it('applies the cooldown exactly as a real run would', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    app(EvaluateRulesAction::class)->handle();

    expect($this->position->fresh()->last_triggered_at)->not->toBeNull();
});

it('simulates one sale per position rather than repeating every cooldown', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    app(EvaluateRulesAction::class)->handle();

    $this->travel(3)->hours();
    freshSnapshot();
    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('does not let a past simulated order block a real run', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);
    app(EvaluateRulesAction::class)->handle();

    Setting::setBool(Setting::DRY_RUN, false);
    Http::fake(['*' => Http::response([['order_id' => 'ORD-REAL']], 200)]);

    $this->travel(3)->hours();
    freshSnapshot();
    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(2)
        ->and(Order::where('status', 'placed')->count())->toBe(1);
});

function freshSnapshot(): void
{
    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '120.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
}

it('places nothing at all while trading is paused, dry run or not', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);
    Setting::setBool(Setting::TRADING_ENABLED, false);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('toggles dry run from the settings page', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/settings/dry-run', ['dry_run' => '1'])
        ->assertRedirect();

    expect(Setting::dryRun())->toBeTrue();

    $this->post('/settings/dry-run', ['dry_run' => '0'])->assertRedirect();

    expect(Setting::dryRun())->toBeFalse();
});

it('warns on the dashboard while dry run is on', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Dry run is on');
});

it('labels simulated orders distinctly in the order list', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);
    app(EvaluateRulesAction::class)->handle();

    $this->actingAs(User::factory()->create())
        ->get('/orders')
        ->assertOk()
        ->assertSee('simulated (dry run)');
});

it('requires authentication to toggle dry run', function (): void {
    $this->post('/settings/dry-run', ['dry_run' => '1'])->assertRedirect('/login');

    expect(Setting::dryRun())->toBeFalse();
});
