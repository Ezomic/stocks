<?php

declare(strict_types=1);

use App\Models\ManualOrder;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake();

    $this->user = User::factory()->create();
    $this->position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => 10, 'currency' => 'USD']);
});

function validOrder(array $overrides = []): array
{
    return array_merge([
        'position_id' => test()->position->id,
        'side' => 'buy',
        'status' => 'planned',
        'quantity' => '5',
        'price' => '100.50',
        'fees' => '1.25',
        'order_date' => now()->toDateString(),
        'note' => 'via IBKR app',
    ], $overrides);
}

it('requires login', function (): void {
    $this->get('/manual-orders')->assertRedirect('/login');
    $this->post('/manual-orders', validOrder())->assertRedirect('/login');
});

it('records an order against a position without touching the broker or the holding', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder())
        ->assertRedirect(route('manual-orders.index'));

    $order = ManualOrder::firstOrFail();
    expect($order->symbol)->toBe('AAPL')
        ->and($order->position_id)->toBe($this->position->id)
        ->and($order->status)->toBe('planned')
        ->and((float) $order->fees)->toBe(1.25)
        ->and($this->position->fresh()->quantity)->toEqual($this->position->quantity);

    Http::assertNothingSent();
});

it('records a buy of an instrument that is not held yet', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder([
        'position_id' => '',
        'symbol' => ' msft ',
        'currency' => 'eur',
    ]))->assertRedirect();

    expect(ManualOrder::firstOrFail())
        ->symbol->toBe('MSFT')
        ->currency->toBe('EUR')
        ->position_id->toBeNull();
});

it('requires a position for a sell', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['side' => 'sell', 'position_id' => '', 'symbol' => 'MSFT']))
        ->assertSessionHasErrors('position_id');
});

it('refuses a planned sell larger than the holding', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['side' => 'sell', 'quantity' => '10.5']))
        ->assertSessionHasErrors('quantity');

    expect(ManualOrder::count())->toBe(0);
});

it('counts sells that are already planned against the holding', function (): void {
    ManualOrder::factory()->create(['position_id' => $this->position->id, 'side' => 'sell', 'quantity' => 8, 'status' => 'planned']);

    $this->actingAs($this->user)->post('/manual-orders', validOrder(['side' => 'sell', 'quantity' => '3']))
        ->assertSessionHasErrors('quantity');
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['side' => 'sell', 'quantity' => '2']))
        ->assertSessionDoesntHaveErrors();
});

it('does not check history sells against the holding', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['side' => 'sell', 'status' => 'filled', 'quantity' => '50']))
        ->assertSessionDoesntHaveErrors();
});

it('requires a price and a past date once an order is filled', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['status' => 'filled', 'price' => '']))
        ->assertSessionHasErrors('price');
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['status' => 'filled', 'order_date' => now()->addDay()->toDateString()]))
        ->assertSessionHasErrors('order_date');
});

it('allows a planned order without a price', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['price' => '']))
        ->assertSessionDoesntHaveErrors();

    expect(ManualOrder::firstOrFail()->price)->toBeNull();
});

it('rejects malformed input', function (string $field, mixed $value): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder([$field => $value]))
        ->assertSessionHasErrors($field);
})->with([
    'zero quantity' => ['quantity', '0'],
    'negative quantity' => ['quantity', '-1'],
    'scientific quantity' => ['quantity', '1e3'],
    'text quantity' => ['quantity', 'abc'],
    'negative price' => ['price', '-5'],
    'five decimal price' => ['price', '1.12345'],
    'negative fees' => ['fees', '-1'],
    'unknown side' => ['side', 'short'],
    'unknown status' => ['status', 'placed'],
    'bad date' => ['order_date', '06-11-2026'],
    'long note' => ['note', str_repeat('a', 1001)],
]);

it('rejects a symbol with odd characters when no position is chosen', function (): void {
    $this->actingAs($this->user)->post('/manual-orders', validOrder(['position_id' => '', 'symbol' => 'A B;']))
        ->assertSessionHasErrors('symbol');
});

it('lists orders newest first and filters by status', function (): void {
    ManualOrder::factory()->create(['symbol' => 'OLDX', 'order_date' => now()->subDays(5)->toDateString(), 'status' => 'filled']);
    ManualOrder::factory()->create(['symbol' => 'NEWX', 'order_date' => now()->toDateString(), 'status' => 'planned']);

    $this->actingAs($this->user)->get('/manual-orders')
        ->assertOk()
        ->assertSeeInOrder(['NEWX', 'OLDX'])
        ->assertSee('record only');

    $this->actingAs($this->user)->get('/manual-orders?status=filled')
        ->assertSee('OLDX')
        ->assertDontSee('NEWX');
});

it('edits an order, including moving it through its statuses', function (): void {
    $order = ManualOrder::factory()->create(['position_id' => $this->position->id, 'side' => 'buy', 'status' => 'planned', 'price' => null]);

    $this->actingAs($this->user)->get(route('manual-orders.edit', $order))->assertOk();

    $this->actingAs($this->user)->put(route('manual-orders.update', $order), validOrder(['status' => 'filled', 'quantity' => '4']))
        ->assertRedirect(route('manual-orders.index'));

    expect($order->fresh())->status->toBe('filled')->and((float) $order->fresh()->quantity)->toBe(4.0);
});

it('does not count the order being edited against the holding', function (): void {
    $order = ManualOrder::factory()->create(['position_id' => $this->position->id, 'side' => 'sell', 'quantity' => 10, 'status' => 'planned']);

    $this->actingAs($this->user)->put(route('manual-orders.update', $order), validOrder(['side' => 'sell', 'quantity' => '9']))
        ->assertSessionDoesntHaveErrors();
});

it('keeps the log when a position is deleted', function (): void {
    $order = ManualOrder::factory()->create(['position_id' => $this->position->id, 'symbol' => 'AAPL']);

    $this->position->delete();

    expect($order->fresh())->position_id->toBeNull()->symbol->toBe('AAPL');
});

it('preselects the position from the position page link', function (): void {
    $this->actingAs($this->user)->get(route('positions.show', $this->position))->assertSee('Log order');
    $this->actingAs($this->user)->get(route('manual-orders.create', ['position_id' => $this->position->id, 'side' => 'sell']))
        ->assertOk()
        ->assertSee('value="'.$this->position->id.'" selected', false);
});
