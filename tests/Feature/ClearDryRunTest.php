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
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $this->user = User::factory()->create();
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
        'price' => '150.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
});

it('clears simulated orders', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);
    app(EvaluateRulesAction::class)->handle();

    expect(Order::where('status', 'simulated')->count())->toBe(1);

    $this->actingAs($this->user)
        ->post(route('settings.dry-run.clear'))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Order::count())->toBe(0);
});

it('never touches a real order', function (): void {
    foreach (['placed', 'filled', 'failed', 'cancelled', 'pending', 'unreconciled'] as $status) {
        Order::factory()->create(['position_id' => $this->position->id, 'status' => $status]);
    }

    Order::factory()->create(['position_id' => $this->position->id, 'status' => 'simulated']);

    $this->actingAs($this->user)->post(route('settings.dry-run.clear'));

    expect(Order::count())->toBe(6)
        ->and(Order::where('status', 'simulated')->count())->toBe(0);
});

it('lets a position trigger again in the simulation after clearing', function (): void {
    Setting::setBool(Setting::DRY_RUN, true);

    app(EvaluateRulesAction::class)->handle();
    expect(Order::count())->toBe(1);

    // Blocked while the simulated order stands in for the closed position.
    $this->travel(3)->hours();
    PriceSnapshot::create([
        'symbol' => 'AAPL', 'price' => '150.00', 'currency' => 'USD', 'source' => 'ibkr', 'fetched_at' => now(),
    ]);
    app(EvaluateRulesAction::class)->handle();
    expect(Order::count())->toBe(1);

    $this->actingAs($this->user)->post(route('settings.dry-run.clear'));

    app(EvaluateRulesAction::class)->handle();

    expect(Order::where('status', 'simulated')->count())->toBe(1);
});

it('says so when there is nothing to clear', function (): void {
    $this->actingAs($this->user)
        ->post(route('settings.dry-run.clear'))
        ->assertSessionHas('success', 'There were no simulated orders to clear.');
});

it('offers the control only when simulated orders exist', function (): void {
    $this->actingAs($this->user)->get('/settings')->assertOk()->assertDontSee('Clear simulated orders');

    Order::factory()->create(['position_id' => $this->position->id, 'status' => 'simulated']);

    $this->get('/settings')->assertOk()->assertSee('Clear simulated orders');
});

it('keeps clearing behind authentication', function (): void {
    Order::factory()->create(['position_id' => $this->position->id, 'status' => 'simulated']);

    $this->post(route('settings.dry-run.clear'))->assertRedirect('/login');

    expect(Order::where('status', 'simulated')->count())->toBe(1);
});
