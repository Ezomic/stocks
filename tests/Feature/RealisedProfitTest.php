<?php

declare(strict_types=1);

use App\Actions\PlaceOrderAction;
use App\Models\Order;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->user = User::factory()->create();
    $this->position = Position::factory()->create([
        'symbol' => 'AAPL',
        'avg_buy_price' => '100.00',
        'quantity' => '10',
        'currency' => 'USD',
        'ibkr_con_id' => '265598',
    ]);
});

function closedTrade(array $overrides = []): Order
{
    return Order::factory()->create(array_merge([
        'symbol' => 'AAPL',
        'side' => 'sell',
        'quantity' => '10',
        'status' => 'filled',
        'fill_price' => '150.00',
        'cost_basis' => '100.00',
        'currency' => 'USD',
        'filled_at' => now(),
    ], $overrides));
}

it('works out what a closed trade made', function (): void {
    expect(closedTrade()->realisedProfit())->toBe(500.0);
});

it('works out a loss', function (): void {
    expect(closedTrade(['fill_price' => '80.00'])->realisedProfit())->toBe(-200.0);
});

it('reports nothing for an order that has not filled', function (): void {
    expect(closedTrade(['status' => 'placed'])->realisedProfit())->toBeNull();
});

it('reports nothing without a cost basis to measure against', function (): void {
    expect(closedTrade(['cost_basis' => null])->realisedProfit())->toBeNull();
});

it('captures the cost basis at the moment the order is raised', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    expect((float) $order->cost_basis)->toBe(100.0)
        ->and($order->currency)->toBe('USD');
});

it('does not rewrite history when the position average later moves', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::orderPlaced(), 200)]);

    $order = app(PlaceOrderAction::class)->handle($this->position, 'sell');

    $this->position->update(['avg_buy_price' => '250.00']);

    expect((float) $order->fresh()->cost_basis)->toBe(100.0);
});

it('totals realised profit per currency', function (): void {
    closedTrade(['symbol' => 'AAPL', 'currency' => 'USD']);
    closedTrade(['symbol' => 'MSFT', 'currency' => 'USD', 'fill_price' => '120.00', 'quantity' => '5']);
    closedTrade(['symbol' => 'ASML', 'currency' => 'EUR', 'fill_price' => '110.00', 'quantity' => '2']);

    $this->actingAs($this->user)->get('/trades')
        ->assertOk()
        ->assertSee('Realised (USD)')
        ->assertSee('USD 600.00')
        ->assertSee('Realised (EUR)')
        ->assertSee('EUR 20.00');
});

it('never adds one currency to another', function (): void {
    closedTrade(['currency' => 'USD']);
    closedTrade(['symbol' => 'ASML', 'currency' => 'EUR']);

    $this->actingAs($this->user)->get('/trades')
        ->assertOk()
        ->assertDontSee('1,000.00');
});

it('says out loud that the figures are average-cost rather than FIFO', function (): void {
    closedTrade();

    $this->actingAs($this->user)->get('/trades')
        ->assertOk()
        ->assertSee('average-cost, not FIFO')
        ->assertSee('will not match your broker statement');
});

it('leaves unfilled and buy orders out of the history', function (): void {
    closedTrade(['status' => 'placed', 'symbol' => 'PENDING']);
    closedTrade(['side' => 'buy', 'symbol' => 'BOUGHT']);
    closedTrade(['symbol' => 'SOLD']);

    $this->actingAs($this->user)->get('/trades')
        ->assertOk()
        ->assertSee('SOLD')
        ->assertDontSee('PENDING')
        ->assertDontSee('BOUGHT');
});

it('still lists a trade whose position was deleted', function (): void {
    closedTrade(['position_id' => null, 'symbol' => 'GONE']);

    $this->actingAs($this->user)->get('/trades')
        ->assertOk()
        ->assertSee('GONE')
        ->assertSee('deleted');
});

it('copes with no closed trades', function (): void {
    $this->actingAs($this->user)->get('/trades')
        ->assertOk()
        ->assertSee('No closed trades yet');
});

it('keeps the trade history behind authentication', function (): void {
    $this->get('/trades')->assertRedirect('/login');
});
