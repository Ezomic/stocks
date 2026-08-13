<?php

declare(strict_types=1);

use App\Actions\SyncPricesAction;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\User;
use App\Models\WatchlistItem;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();

    $this->user = User::factory()->create();
});

function fakeContractSearch(array $rows): void
{
    Http::fake(['*/iserver/secdef/search' => Http::response($rows, 200)]);
}

it('finds a contract id by symbol', function (): void {
    fakeContractSearch([
        ['conid' => 265598, 'symbol' => 'AAPL', 'companyHeader' => 'APPLE INC', 'description' => 'NASDAQ'],
    ]);

    $this->actingAs($this->user)
        ->get(route('watchlist.index', ['symbol' => 'AAPL']))
        ->assertOk()
        ->assertSee('265598')
        ->assertSee('APPLE INC');
});

it('says so when a search comes back with nothing', function (): void {
    fakeContractSearch([]);

    $this->actingAs($this->user)
        ->get(route('watchlist.index', ['symbol' => 'NOPE']))
        ->assertOk()
        ->assertSee('No contracts came back');
});

it('does not search until a symbol is given', function (): void {
    $this->actingAs($this->user)->get(route('watchlist.index'))->assertOk();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'secdef/search'));
});

it('survives the gateway refusing a search', function (): void {
    Http::fake(['*/iserver/secdef/search' => Http::response(IbkrFakeResponses::authFailure(), 401)]);

    $this->actingAs($this->user)
        ->get(route('watchlist.index', ['symbol' => 'AAPL']))
        ->assertOk()
        ->assertSee('No contracts came back');
});

it('adds a symbol to the watchlist', function (): void {
    $this->actingAs($this->user)
        ->post(route('watchlist.store'), [
            'symbol' => 'AAPL',
            'ibkr_con_id' => '265598',
            'currency' => 'USD',
            'market' => 'STK',
        ])
        ->assertRedirect(route('watchlist.index'));

    expect(WatchlistItem::sole()->symbol)->toBe('AAPL');
});

it('refuses the same contract twice', function (): void {
    WatchlistItem::create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598', 'currency' => 'USD', 'market' => 'STK']);

    $this->actingAs($this->user)
        ->post(route('watchlist.store'), [
            'symbol' => 'AAPL',
            'ibkr_con_id' => '265598',
            'currency' => 'USD',
            'market' => 'STK',
        ])
        ->assertSessionHasErrors('ibkr_con_id');

    expect(WatchlistItem::count())->toBe(1);
});

it('removes a symbol from the watchlist', function (): void {
    $item = WatchlistItem::create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598', 'currency' => 'USD', 'market' => 'STK']);

    $this->actingAs($this->user)
        ->delete(route('watchlist.destroy', $item))
        ->assertRedirect(route('watchlist.index'));

    expect(WatchlistItem::count())->toBe(0);
});

it('prices watchlist entries alongside positions', function (): void {
    Position::factory()->create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598', 'currency' => 'USD']);
    WatchlistItem::create(['symbol' => 'TSLA', 'ibkr_con_id' => '76792991', 'currency' => 'USD', 'market' => 'STK']);

    Http::fake(['*/marketdata/snapshot*' => Http::response(IbkrFakeResponses::snapshot([
        265598 => '118.40',
        76792991 => '183.10',
    ]), 200)]);

    app(SyncPricesAction::class)->handle();

    expect((float) PriceSnapshot::latestFor('AAPL')->price)->toBe(118.40)
        ->and((float) PriceSnapshot::latestFor('TSLA')->price)->toBe(183.10);
});

it('prices a watchlist entry even with no positions at all', function (): void {
    WatchlistItem::create(['symbol' => 'TSLA', 'ibkr_con_id' => '76792991', 'currency' => 'USD', 'market' => 'STK']);

    Http::fake(['*/marketdata/snapshot*' => Http::response(IbkrFakeResponses::snapshot([76792991 => '183.10']), 200)]);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::where('symbol', 'TSLA')->count())->toBe(1);
});

it('does not ask for the same contract twice when it is both held and watched', function (): void {
    Position::factory()->create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598', 'currency' => 'USD']);
    WatchlistItem::create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598', 'currency' => 'USD', 'market' => 'STK']);

    Http::fake(['*/marketdata/snapshot*' => Http::response(IbkrFakeResponses::snapshot([265598 => '118.40']), 200)]);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::where('symbol', 'AAPL')->count())->toBe(1);
});

it('shows the last price on the watchlist', function (): void {
    WatchlistItem::create(['symbol' => 'TSLA', 'ibkr_con_id' => '76792991', 'currency' => 'USD', 'market' => 'STK']);

    PriceSnapshot::create([
        'symbol' => 'TSLA', 'price' => '183.10', 'currency' => 'USD',
        'source' => 'ibkr', 'fetched_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->get(route('watchlist.index'))
        ->assertOk()
        ->assertSee('USD 183.10');
});

it('says when a watchlist entry has not been priced yet', function (): void {
    WatchlistItem::create(['symbol' => 'TSLA', 'ibkr_con_id' => '76792991', 'currency' => 'USD', 'market' => 'STK']);

    $this->actingAs($this->user)
        ->get(route('watchlist.index'))
        ->assertOk()
        ->assertSee('not priced yet');
});

it('keeps the watchlist behind authentication', function (): void {
    $this->get(route('watchlist.index'))->assertRedirect('/login');
    $this->post(route('watchlist.store'), [])->assertRedirect('/login');
});
