<?php

declare(strict_types=1);

use App\Actions\SyncPricesAction;
use App\Models\Position;
use App\Models\PriceSnapshot;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('stores a snapshot for every position it gets a price for', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::snapshot([
        265598 => '118.40',
        76792991 => '183.10',
    ]), 200)]);

    Position::factory()->create(['symbol' => 'AAPL', 'currency' => 'USD', 'ibkr_con_id' => '265598']);
    Position::factory()->create(['symbol' => 'TSLA', 'currency' => 'USD', 'ibkr_con_id' => '76792991']);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::count())->toBe(2)
        ->and((float) PriceSnapshot::latestFor('AAPL')->price)->toBe(118.40)
        ->and((float) PriceSnapshot::latestFor('TSLA')->price)->toBe(183.10)
        ->and(PriceSnapshot::latestFor('AAPL')->source)->toBe('ibkr');
});

it('retries once when the first call comes back without prices', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(IbkrFakeResponses::snapshotNotSubscribedYet([265598]), 200)
        ->push(IbkrFakeResponses::snapshot([265598 => '118.40']), 200),
    ]);

    Position::factory()->create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598']);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::count())->toBe(1)
        ->and((float) PriceSnapshot::latestFor('AAPL')->price)->toBe(118.40);

    Http::assertSentCount(2);
});

it('gives up quietly when the retry also comes back without prices', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::snapshotNotSubscribedYet([265598]), 200)]);

    Position::factory()->create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598']);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::count())->toBe(0);
    Http::assertSentCount(2);
});

it('skips a position the gateway returned no price row for', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::snapshot([265598 => '118.40']), 200)]);

    Position::factory()->create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598']);
    Position::factory()->create(['symbol' => 'TSLA', 'ibkr_con_id' => '76792991']);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::where('symbol', 'AAPL')->count())->toBe(1)
        ->and(PriceSnapshot::where('symbol', 'TSLA')->count())->toBe(0);
});

it('ignores positions with no conid', function (): void {
    Position::factory()->create(['symbol' => 'AAPL', 'ibkr_con_id' => null]);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::count())->toBe(0);
    Http::assertNothingSent();
});

it('does nothing at all when there are no positions', function (): void {
    app(SyncPricesAction::class)->handle();

    Http::assertNothingSent();
});

it('asks in batches of fifty conids', function (): void {
    $prices = [];

    for ($i = 1; $i <= 60; $i++) {
        Position::factory()->create(['symbol' => "SYM{$i}", 'ibkr_con_id' => (string) $i]);
        $prices[$i] = '10.00';
    }

    Http::fake(['*' => Http::response(IbkrFakeResponses::snapshot($prices), 200)]);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::count())->toBe(60);

    Http::assertSentCount(2);
    Http::assertSent(function ($request): bool {
        $conids = $request->data()['conids'] ?? '';

        return count(explode(',', (string) $conids)) <= 50;
    });
});

it('writes nothing when the gateway errors', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::authFailure(), 401)]);

    Position::factory()->create(['symbol' => 'AAPL', 'ibkr_con_id' => '265598']);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::count())->toBe(0);
});

it('records the position currency on the snapshot', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::snapshot([265598 => '118.40']), 200)]);

    Position::factory()->create(['symbol' => 'ASML', 'currency' => 'EUR', 'ibkr_con_id' => '265598']);

    app(SyncPricesAction::class)->handle();

    expect(PriceSnapshot::latestFor('ASML')->currency)->toBe('EUR');
});
