<?php

declare(strict_types=1);

use App\Actions\RecordPortfolioValueAction;
use App\Models\PortfolioValue;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->user = User::factory()->create();
});

function heldPosition(string $symbol, string $currency, string $avgBuy, string $quantity, ?string $price = null): Position
{
    $position = Position::factory()->create([
        'symbol' => $symbol,
        'currency' => $currency,
        'avg_buy_price' => $avgBuy,
        'quantity' => $quantity,
    ]);

    if ($price !== null) {
        PriceSnapshot::create([
            'symbol' => $symbol,
            'price' => $price,
            'currency' => $currency,
            'source' => 'ibkr',
            'fetched_at' => now(),
        ]);
    }

    return $position;
}

it('records a total per currency', function (): void {
    heldPosition('AAPL', 'USD', '100', '10', '120');
    heldPosition('ASML', 'EUR', '500', '2', '600');

    expect(app(RecordPortfolioValueAction::class)->handle())->toBe(2);

    expect((float) PortfolioValue::where('currency', 'USD')->sole()->value)->toBe(1200.0)
        ->and((float) PortfolioValue::where('currency', 'EUR')->sole()->value)->toBe(1200.0);
});

it('records the cost alongside the value so gain can be derived', function (): void {
    heldPosition('AAPL', 'USD', '100', '10', '120');

    app(RecordPortfolioValueAction::class)->handle();

    $row = PortfolioValue::sole();

    expect((float) $row->cost)->toBe(1000.0)
        ->and($row->gainPct())->toBe(20.0);
});

it('keeps one row per day per currency', function (): void {
    heldPosition('AAPL', 'USD', '100', '10', '120');

    app(RecordPortfolioValueAction::class)->handle();
    app(RecordPortfolioValueAction::class)->handle();

    expect(PortfolioValue::count())->toBe(1);
});

it('updates the day it already recorded rather than duplicating it', function (): void {
    $position = heldPosition('AAPL', 'USD', '100', '10', '120');

    app(RecordPortfolioValueAction::class)->handle();

    $position->update(['quantity' => '20']);
    app(RecordPortfolioValueAction::class)->handle();

    expect((float) PortfolioValue::sole()->value)->toBe(2400.0);
});

it('leaves a position with no price out of both sides of the sum', function (): void {
    heldPosition('AAPL', 'USD', '100', '10', '120');
    heldPosition('NOPRICE', 'USD', '999', '5');

    app(RecordPortfolioValueAction::class)->handle();

    $row = PortfolioValue::sole();

    // Counting its cost but not its value would show a permanent phantom loss.
    expect((float) $row->value)->toBe(1200.0)
        ->and((float) $row->cost)->toBe(1000.0);
});

it('ignores positions on another account', function (): void {
    heldPosition('AAPL', 'USD', '100', '10', '120');

    Position::factory()->create([
        'symbol' => 'THEIRS',
        'currency' => 'USD',
        'quantity' => '100',
        'avg_buy_price' => '100',
        'account_mode' => 'live',
        'broker_account_id' => 'U0000001',
    ]);

    app(RecordPortfolioValueAction::class)->handle();

    expect((float) PortfolioValue::sole()->value)->toBe(1200.0);
});

it('survives the snapshot prune that would erase a derived chart', function (): void {
    heldPosition('AAPL', 'USD', '100', '10', '120');

    app(RecordPortfolioValueAction::class)->handle();

    PriceSnapshot::query()->delete();

    expect(PortfolioValue::count())->toBe(1)
        ->and((float) PortfolioValue::sole()->value)->toBe(1200.0);
});

it('does not rewrite an earlier day when a position is later deleted', function (): void {
    $position = heldPosition('AAPL', 'USD', '100', '10', '120');

    app(RecordPortfolioValueAction::class)->handle();

    $position->delete();

    expect((float) PortfolioValue::sole()->value)->toBe(1200.0);
});

it('charts the history on the dashboard', function (): void {
    foreach ([['2026-08-01', '1000'], ['2026-08-02', '1100'], ['2026-08-03', '1250']] as [$day, $value]) {
        PortfolioValue::create([
            'recorded_on' => $day, 'currency' => 'USD',
            'value' => $value, 'cost' => '1000', 'positions' => 1,
        ]);
    }

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('Portfolio value (USD)')
        ->assertSee('<polyline', false);
});

it('does not chart a single point', function (): void {
    PortfolioValue::create([
        'recorded_on' => '2026-08-01', 'currency' => 'USD',
        'value' => '1000', 'cost' => '1000', 'positions' => 1,
    ]);

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertDontSee('Portfolio value (USD)');
});

it('reports what it recorded from the command', function (): void {
    heldPosition('AAPL', 'USD', '100', '10', '120');

    $this->artisan('portfolio:record')
        ->expectsOutputToContain('Recorded the portfolio value for 1 currency')
        ->assertSuccessful();
});

it('records daily, before the snapshot prune runs', function (): void {
    $events = collect(app(Schedule::class)->events());

    $record = $events->first(fn ($e): bool => str_contains((string) $e->command, 'portfolio:record'));
    $prune = $events->first(fn ($e): bool => str_contains((string) $e->command, 'prices:prune'));

    expect($record->expression)->toBe('0 2 * * *')
        ->and($prune->expression)->toBe('15 2 * * *');
});
