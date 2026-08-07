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
        'ibkr_con_id' => '265598',
    ]);

    PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '150.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
});

it('does not trade a position whose own rule is paused', function (): void {
    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => false,
        'cooldown_minutes' => 60,
    ]);

    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('trades a position whose own rule is active', function (): void {
    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1);
});

it('falls back to the global rule only when the position has none of its own', function (): void {
    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1)
        ->and(Order::sole()->rule->isGlobal())->toBeTrue();
});

it('does not trade under a paused global rule', function (): void {
    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '5.00',
        'is_active' => false,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(0);
});

it('attributes the order to the position rule, not the global one', function (): void {
    $positionRule = Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    app(EvaluateRulesAction::class)->handle();

    expect(Order::sole()->rule_id)->toBe($positionRule->id);
});

it('resolves the governing rule on the model', function (): void {
    $global = Rule::factory()->make(['position_id' => null, 'is_active' => true]);
    $paused = Rule::factory()->make(['is_active' => false]);
    $active = Rule::factory()->make(['is_active' => true]);

    $withPaused = Position::factory()->make();
    $withPaused->setRelation('rule', $paused);

    $withActive = Position::factory()->make();
    $withActive->setRelation('rule', $active);

    $withNone = Position::factory()->make();
    $withNone->setRelation('rule', null);

    expect($withPaused->activeRule($global))->toBeNull()
        ->and($withActive->activeRule($global))->toBe($active)
        ->and($withNone->activeRule($global))->toBe($global)
        ->and($withNone->activeRule(null))->toBeNull();
});

it('shows a paused position rule as paused rather than as no rule', function (): void {
    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => '10.00',
        'is_active' => false,
        'cooldown_minutes' => 60,
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Paused');
});

it('marks a position governed by the global rule as such', function (): void {
    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertOk()
        ->assertSee('Global:');
});

it('lists on the rules page which positions the global rule governs', function (): void {
    Rule::factory()->create([
        'position_id' => null,
        'take_profit_pct' => '5.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    $this->actingAs(User::factory()->create())
        ->get('/rules')
        ->assertOk()
        ->assertSee('Evaluates 1 position')
        ->assertSee('AAPL');
});
