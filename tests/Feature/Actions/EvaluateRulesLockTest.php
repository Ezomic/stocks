<?php

declare(strict_types=1);

use App\Actions\EvaluateRulesAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Models\User;
use App\Services\MarketHours;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $this->position = Position::factory()->create([
        'symbol' => 'TSLA',
        'avg_buy_price' => '200.00',
        'quantity' => '5',
        'ibkr_con_id' => '76792991',
    ]);

    Rule::factory()->create([
        'position_id' => $this->position->id,
        'take_profit_pct' => null,
        'stop_loss_pct' => '10.00',
        'is_active' => true,
        'cooldown_minutes' => 60,
    ]);

    PriceSnapshot::create([
        'symbol' => 'TSLA',
        'price' => '170.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);
});

it('evaluates nothing while another run holds the lock', function (): void {
    $lock = Cache::lock(EvaluateRulesAction::LOCK, 60);
    $lock->get();

    expect(app(EvaluateRulesAction::class)->handle())->toBeFalse()
        ->and(Order::count())->toBe(0);

    $lock->release();
});

it('releases the lock so the next run can evaluate', function (): void {
    expect(app(EvaluateRulesAction::class)->handle())->toBeTrue()
        ->and(Order::count())->toBe(1)
        ->and(Cache::lock(EvaluateRulesAction::LOCK, 60)->get())->toBeTrue();
});

it('makes the settings button wait for a scheduled run in progress', function (): void {
    $lock = Cache::lock(EvaluateRulesAction::LOCK, 60);
    $lock->get();

    $this->actingAs(User::factory()->create())
        ->from('/settings')
        ->post('/settings/evaluate-rules')
        ->assertRedirect('/settings')
        ->assertSessionHas('error');

    expect(Order::count())->toBe(0);

    $lock->release();
});

it('makes the scheduled command skip while the button holds the lock', function (): void {
    $lock = Cache::lock(EvaluateRulesAction::LOCK, 60);
    $lock->get();

    $this->artisan('ibkr:evaluate-rules')->assertSuccessful();

    expect(Order::count())->toBe(0);

    $lock->release();
});

it('checks for an open order again right before placing', function (): void {
    // Stands in for another run placing an order between the open-order read at the start of
    // this run and the moment this position is acted on.
    $this->mock(MarketHours::class)
        ->shouldReceive('isOpen')
        ->andReturnUsing(function (Position $position): bool {
            Order::factory()->create([
                'position_id' => $position->id,
                'side' => 'sell',
                'status' => 'placed',
            ]);

            return true;
        });

    app(EvaluateRulesAction::class)->handle();

    expect(Order::count())->toBe(1)
        ->and(Order::first()->status)->toBe('placed')
        ->and($this->position->fresh()->last_triggered_at)->toBeNull();
});
