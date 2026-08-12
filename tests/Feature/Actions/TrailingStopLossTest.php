<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
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
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'currency' => 'USD',
        'ibkr_con_id' => '265598',
    ]);
});

function priceAt(string $price, int $minutesAgo = 0): void
{
    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => $price,
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now()->subMinutes($minutesAgo),
    ]);
}

function trailingRule(Position $position, string $stopPct = '10.00'): Rule
{
    return Rule::factory()->create([
        'position_id' => $position->id,
        'take_profit_pct' => null,
        'stop_loss_pct' => $stopPct,
        'stop_loss_type' => 'trailing',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);
}

it('sells when the price falls from its peak even while still above the entry price', function (): void {
    trailingRule($this->position);

    priceAt('140.00', 30);
    priceAt('125.00', 0);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('is the case a fixed stop misses entirely', function (): void {
    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => null,
        'stop_loss_pct' => '10.00',
        'stop_loss_type' => 'entry',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    priceAt('140.00', 30);
    priceAt('125.00', 0);

    app(EvaluateRulesAction::class)->handle();

    // 125 is still 25% up on a 100 entry, so a fixed stop does nothing.
    expect(Order::count())->toBe(0);
});

it('holds while the fall from peak is inside the threshold', function (): void {
    trailingRule($this->position);

    priceAt('140.00', 30);
    priceAt('130.00', 0);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('fires exactly at the trigger price', function (): void {
    trailingRule($this->position);

    priceAt('200.00', 30);
    priceAt('180.00', 0);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('follows the peak upward so the trigger rises with the price', function (): void {
    $rule = trailingRule($this->position);

    priceAt('120.00', 60);
    expect($rule->stopLossPrice($this->position, PriceSnapshot::peakFor('AAPL')))->toBe(108.0);

    priceAt('200.00', 30);
    expect($rule->stopLossPrice($this->position, PriceSnapshot::peakFor('AAPL')))->toBe(180.0);
});

it('never lowers the trigger when the price falls back', function (): void {
    $rule = trailingRule($this->position);

    priceAt('200.00', 60);
    priceAt('150.00', 0);

    expect($rule->stopLossPrice($this->position, PriceSnapshot::peakFor('AAPL')))->toBe(180.0);
});

it('leaves a fixed stop measured from the entry price', function (): void {
    $rule = Rule::factory()->create([
        'position_id' => $this->position->id,
        'stop_loss_pct' => '10.00',
        'stop_loss_type' => 'entry',
        'is_active' => true,
    ]);

    priceAt('200.00', 30);

    expect($rule->stopLossPrice($this->position, PriceSnapshot::peakFor('AAPL')))->toBe(90.0);
});

it('does not fire a trailing stop with no price history to peak against', function (): void {
    $rule = trailingRule($this->position);

    expect($rule->stopLossPrice($this->position, null))->toBeNull();
});

it('still honours take profit alongside a trailing stop', function (): void {
    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => '50.00',
        'stop_loss_type' => 'trailing',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    priceAt('115.00');

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('defaults an existing rule to a fixed stop', function (): void {
    $rule = Rule::factory()->create(['position_id' => $this->position->id]);

    expect($rule->stop_loss_type)->toBe('entry')
        ->and($rule->isTrailing())->toBeFalse();
});

it('saves the stop type from the rule form', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/rules', [
            'position_id' => (string) $this->position->id,
            'stop_loss_pct' => '8',
            'stop_loss_type' => 'trailing',
            'cooldown_minutes' => '60',
            'is_active' => '1',
        ])
        ->assertSessionHasNoErrors();

    expect(Rule::sole()->isTrailing())->toBeTrue();
});

it('rejects an unknown stop type', function (): void {
    $this->actingAs(User::factory()->create())
        ->post('/rules', [
            'position_id' => (string) $this->position->id,
            'stop_loss_pct' => '8',
            'stop_loss_type' => 'nonsense',
            'cooldown_minutes' => '60',
        ])
        ->assertSessionHasErrors('stop_loss_type');
});

it('shows the peak and both trigger prices on the position page', function (): void {
    trailingRule($this->position);

    priceAt('200.00', 30);
    priceAt('190.00', 0);

    $this->actingAs(User::factory()->create())
        ->get("/positions/{$this->position->id}")
        ->assertOk()
        ->assertSee('Peak price')
        ->assertSee('USD 200.00')
        ->assertSee('USD 180.00')
        ->assertSee('not necessarily since you bought');
});
