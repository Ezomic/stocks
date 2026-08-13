<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->user = User::factory()->create();
});

function csvFrom(string $route, array $params = []): string
{
    $response = test()->get(route($route, $params));
    $response->assertOk();

    return $response->streamedContent();
}

it('exports orders with the fields reconciliation needs', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'currency' => 'USD']);

    Order::factory()->create([
        'position_id' => $position->id,
        'symbol' => 'AAPL',
        'side' => 'sell',
        'quantity' => '10',
        'status' => 'filled',
        'broker_order_id' => 'ORD-77',
        'fill_price' => '150.00',
        'cost_basis' => '100.00',
        'currency' => 'USD',
        'filled_at' => now(),
    ]);

    $this->actingAs($this->user);
    $csv = csvFrom('orders.export');

    expect($csv)->toContain('symbol,side,quantity')
        ->and($csv)->toContain('AAPL')
        ->and($csv)->toContain('ORD-77')
        ->and($csv)->toContain('150.00');
});

it('includes the realised result so a statement can be checked against it', function (): void {
    Order::factory()->create([
        'symbol' => 'AAPL',
        'side' => 'sell',
        'quantity' => '10',
        'status' => 'filled',
        'fill_price' => '150.00',
        'cost_basis' => '100.00',
        'currency' => 'USD',
    ]);

    $this->actingAs($this->user);

    expect(csvFrom('orders.export'))->toContain('realised_profit')
        ->and(csvFrom('orders.export'))->toContain('500');
});

it('exports positions', function (): void {
    Position::factory()->create([
        'symbol' => 'AAPL',
        'quantity' => '10',
        'avg_buy_price' => '100.00',
        'currency' => 'USD',
        'ibkr_con_id' => '265598',
    ]);

    $this->actingAs($this->user);
    $csv = csvFrom('positions.export');

    expect($csv)->toContain('symbol,account_mode')
        ->and($csv)->toContain('AAPL')
        ->and($csv)->toContain('265598');
});

it('filters the order export to match what is on screen', function (): void {
    Order::factory()->create(['symbol' => 'FILLED', 'status' => 'filled', 'fill_price' => '10']);
    Order::factory()->create(['symbol' => 'FAILED', 'status' => 'failed']);

    $this->actingAs($this->user);
    $csv = csvFrom('orders.export', ['status' => 'filled']);

    expect($csv)->toContain('FILLED')
        ->and($csv)->not->toContain('FAILED');
});

it('offers the whole log when no filter is given', function (): void {
    Order::factory()->create(['symbol' => 'ONE', 'status' => 'filled', 'fill_price' => '10']);
    Order::factory()->create(['symbol' => 'TWO', 'status' => 'failed']);

    $this->actingAs($this->user);
    $csv = csvFrom('orders.export');

    expect($csv)->toContain('ONE')->and($csv)->toContain('TWO');
});

it('sends it as a dated download rather than a page', function (): void {
    $this->actingAs($this->user)
        ->get(route('orders.export'))
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=utf-8')
        ->assertDownload('orders-'.now()->format('Y-m-d').'.csv');
});

it('still produces a usable file with nothing to export', function (): void {
    $this->actingAs($this->user);

    expect(csvFrom('orders.export'))->toContain('symbol,side,quantity');
});

it('keeps an order whose position was deleted in the export', function (): void {
    Order::factory()->create(['position_id' => null, 'symbol' => 'GONE', 'status' => 'filled', 'fill_price' => '10']);

    $this->actingAs($this->user);

    expect(csvFrom('orders.export'))->toContain('GONE');
});

it('offers the export from both list pages', function (): void {
    $this->actingAs($this->user)->get('/orders')->assertOk()->assertSee('Export CSV');
    $this->actingAs($this->user)->get('/positions')->assertOk()->assertSee('Export CSV');
});

it('does not let the export route be mistaken for a position', function (): void {
    // /positions/{position} would otherwise swallow the word "export".
    $this->actingAs($this->user)->get(route('positions.export'))->assertOk();
});

it('keeps exports behind authentication', function (): void {
    $this->get(route('orders.export'))->assertRedirect('/login');
    $this->get(route('positions.export'))->assertRedirect('/login');
});
