<?php

declare(strict_types=1);

use App\Actions\ReplayRuleAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->user = User::factory()->create();
    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'currency' => 'USD',
    ]);
});

/** @param array<int, array{0: string, 1: int}> $points */
function history(array $points): void
{
    foreach ($points as [$price, $minutesAgo]) {
        PriceSnapshot::create([
            'symbol' => 'AAPL',
            'price' => $price,
            'currency' => 'USD',
            'source' => 'ibkr',
            'fetched_at' => now()->subMinutes($minutesAgo),
        ]);
    }
}

function proposedRule(array $overrides = []): Rule
{
    return new Rule(array_merge([
        'take_profit_pct' => '10.00',
        'stop_loss_pct' => null,
        'stop_loss_type' => 'entry',
        'cooldown_minutes' => 60,
    ], $overrides));
}

it('reports every point the rule would have fired', function (): void {
    history([['105.00', 300], ['115.00', 240], ['108.00', 180]]);

    $result = app(ReplayRuleAction::class)->handle($this->position, proposedRule());

    expect($result['triggers'])->toHaveCount(1)
        ->and($result['triggers'][0]['price'])->toBe(115.0)
        ->and($result['triggers'][0]['threshold'])->toBe('take_profit');
});

it('honours the cooldown so a run of ticks is not counted as many triggers', function (): void {
    history([['120.00', 300], ['121.00', 299], ['122.00', 298], ['123.00', 297]]);

    $result = app(ReplayRuleAction::class)->handle($this->position, proposedRule());

    expect($result['triggers'])->toHaveCount(1);
});

it('fires again once the cooldown has elapsed', function (): void {
    history([['120.00', 300], ['121.00', 180], ['122.00', 60]]);

    $result = app(ReplayRuleAction::class)->handle($this->position, proposedRule(['cooldown_minutes' => 60]));

    expect($result['triggers'])->toHaveCount(3);
});

it('reports stop-loss crossings too', function (): void {
    history([['100.00', 300], ['80.00', 240]]);

    $result = app(ReplayRuleAction::class)->handle(
        $this->position,
        proposedRule(['take_profit_pct' => null, 'stop_loss_pct' => '10.00'])
    );

    expect($result['triggers'])->toHaveCount(1)
        ->and($result['triggers'][0]['threshold'])->toBe('stop_loss');
});

it('walks the trailing peak forward as the replay progresses', function (): void {
    // A trailing stop must not see prices from the future of the point being evaluated.
    history([['100.00', 300], ['140.00', 240], ['125.00', 180]]);

    $result = app(ReplayRuleAction::class)->handle(
        $this->position,
        proposedRule(['take_profit_pct' => null, 'stop_loss_pct' => '10.00', 'stop_loss_type' => 'trailing'])
    );

    expect($result['triggers'])->toHaveCount(1)
        ->and($result['triggers'][0]['price'])->toBe(125.0)
        ->and($result['triggers'][0]['peak'])->toBe(140.0);
});

it('does not fire a trailing stop on the way up', function (): void {
    history([['100.00', 300], ['120.00', 240], ['140.00', 180]]);

    $result = app(ReplayRuleAction::class)->handle(
        $this->position,
        proposedRule(['take_profit_pct' => null, 'stop_loss_pct' => '10.00', 'stop_loss_type' => 'trailing'])
    );

    expect($result['triggers'])->toBeEmpty();
});

it('reports the window it actually covered', function (): void {
    history([['100.00', 300], ['110.00', 60]]);

    $result = app(ReplayRuleAction::class)->handle($this->position, proposedRule());

    expect($result['snapshots'])->toBe(2)
        ->and($result['from']->lessThan($result['to']))->toBeTrue();
});

it('copes with no stored history at all', function (): void {
    $result = app(ReplayRuleAction::class)->handle($this->position, proposedRule());

    expect($result['triggers'])->toBeEmpty()
        ->and($result['snapshots'])->toBe(0)
        ->and($result['from'])->toBeNull();
});

it('changes nothing while replaying', function (): void {
    history([['150.00', 300]]);

    app(ReplayRuleAction::class)->handle($this->position, proposedRule());

    expect(Order::count())->toBe(0)
        ->and(Rule::count())->toBe(0)
        ->and($this->position->fresh()->last_triggered_at)->toBeNull();
});

it('shows the replay with the window and its limits spelled out', function (): void {
    history([['105.00', 300], ['115.00', 240]]);

    $this->actingAs($this->user)
        ->get(route('rules.replay', [
            'position_id' => $this->position->id,
            'take_profit_pct' => '10',
            'cooldown_minutes' => '60',
        ]))
        ->assertOk()
        ->assertSee('Would have fired')
        ->assertSee('a window, not a full backtest')
        ->assertSee('USD 115.00');
});

it('says so when there is nothing to replay against', function (): void {
    $this->actingAs($this->user)
        ->get(route('rules.replay', [
            'position_id' => $this->position->id,
            'take_profit_pct' => '10',
            'cooldown_minutes' => '60',
        ]))
        ->assertOk()
        ->assertSee('no stored price history');
});

it('keeps the replay behind authentication', function (): void {
    $this->get(route('rules.replay', [
        'position_id' => $this->position->id,
        'cooldown_minutes' => '60',
    ]))->assertRedirect('/login');
});
