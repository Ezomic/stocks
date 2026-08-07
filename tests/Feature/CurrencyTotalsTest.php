<?php

declare(strict_types=1);

use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->user = User::factory()->create();
});

function pricedPosition(string $symbol, string $currency, string $avgBuy, string $quantity, string $price): Position
{
    $position = Position::factory()->create([
        'symbol' => $symbol,
        'currency' => $currency,
        'avg_buy_price' => $avgBuy,
        'quantity' => $quantity,
    ]);

    PriceSnapshot::create([
        'symbol' => $symbol,
        'price' => $price,
        'currency' => $currency,
        'source' => 'ibkr',
        'fetched_at' => now(),
    ]);

    return $position;
}

it('reports one total per currency rather than one number across all of them', function (): void {
    pricedPosition('AAPL', 'USD', '100.00', '10', '120.00');
    pricedPosition('ASML', 'EUR', '500.00', '2', '600.00');

    $response = $this->actingAs($this->user)->get('/')->assertOk();

    $response->assertSee('Value (USD)')
        ->assertSee('USD 1,200.00')
        ->assertSee('Value (EUR)')
        ->assertSee('EUR 1,200.00');
});

it('never adds euros to dollars', function (): void {
    pricedPosition('AAPL', 'USD', '100.00', '10', '120.00');
    pricedPosition('ASML', 'EUR', '500.00', '2', '600.00');

    // The old behaviour summed both into a single 2,400.00 labelled with a dollar sign.
    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertDontSee('2,400.00')
        ->assertDontSee('$2,400.00');
});

it('computes gain separately per currency', function (): void {
    pricedPosition('AAPL', 'USD', '100.00', '10', '120.00');
    pricedPosition('ASML', 'EUR', '500.00', '2', '450.00');

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('+20.00%')
        ->assertSee('-10.00%');
});

it('shows a single currency plainly', function (): void {
    pricedPosition('AAPL', 'USD', '100.00', '10', '120.00');

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('Value (USD)')
        ->assertSee('USD 1,200.00')
        ->assertDontSee('Value (EUR)');
});

it('never labels a total with a hardcoded dollar sign', function (): void {
    pricedPosition('ASML', 'EUR', '500.00', '2', '600.00');

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('EUR 1,200.00')
        ->assertDontSee('$1,200.00');
});

it('copes with an empty portfolio', function (): void {
    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('Portfolio value');
});

it('leaves the gain out when there is no cost basis to measure against', function (): void {
    pricedPosition('FREE', 'USD', '0.00', '5', '10.00');

    $this->actingAs($this->user)->get('/')->assertOk()->assertSee('USD 50.00');
});
