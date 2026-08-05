<?php

declare(strict_types=1);

use App\Actions\ImportPositionsFromIbkrAction;
use App\Models\Position;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('imports positions from the broker', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::portfolioPositions(), 200)]);

    $imported = app(ImportPositionsFromIbkrAction::class)->handle();

    $aapl = Position::where('symbol', 'AAPL')->sole();

    expect($imported)->toBe(2)
        ->and(Position::count())->toBe(2)
        ->and((float) $aapl->quantity)->toBe(12.0)
        ->and((float) $aapl->avg_buy_price)->toBe(178.42)
        ->and($aapl->currency)->toBe('USD')
        ->and($aapl->market)->toBe('STK')
        ->and($aapl->ibkr_con_id)->toBe('265598')
        ->and($aapl->account_mode)->toBe('paper')
        ->and($aapl->broker_account_id)->toBe('DU0000001');
});

it('updates an existing position rather than duplicating it', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::portfolioPositions(), 200)]);

    Position::factory()->create([
        'symbol' => 'AAPL',
        'broker_account_id' => 'DU0000001',
        'quantity' => '1',
        'avg_buy_price' => '1.00',
        'notes' => 'keep me',
    ]);

    app(ImportPositionsFromIbkrAction::class)->handle();

    $aapl = Position::where('symbol', 'AAPL')->sole();

    expect(Position::count())->toBe(2)
        ->and((float) $aapl->quantity)->toBe(12.0)
        ->and($aapl->notes)->toBe('keep me');
});

it('falls back to the symbol field when the row has no ticker', function (): void {
    Http::fake(['*' => Http::response([
        ['symbol' => 'ASML', 'conid' => 117589399, 'position' => 3, 'avgCost' => 640.10, 'currency' => 'EUR', 'assetClass' => 'STK'],
    ], 200)]);

    app(ImportPositionsFromIbkrAction::class)->handle();

    expect(Position::where('symbol', 'ASML')->exists())->toBeTrue();
});

it('skips rows with no symbol or no conid', function (): void {
    Http::fake(['*' => Http::response([
        ['conid' => 265598, 'position' => 5],
        ['ticker' => 'NOCONID', 'position' => 5],
        ['ticker' => 'GOOD', 'conid' => 111, 'position' => 5, 'avgCost' => 10.0],
    ], 200)]);

    $imported = app(ImportPositionsFromIbkrAction::class)->handle();

    expect($imported)->toBe(1)
        ->and(Position::pluck('symbol')->all())->toBe(['GOOD']);
});

it('ignores rows that are not arrays', function (): void {
    Http::fake(['*' => Http::response(['nonsense', 42, ['ticker' => 'GOOD', 'conid' => 111, 'position' => 5, 'avgCost' => 10.0]], 200)]);

    expect(app(ImportPositionsFromIbkrAction::class)->handle())->toBe(1);
});

it('imports nothing when the gateway errors', function (): void {
    Http::fake(['*' => Http::response(IbkrFakeResponses::authFailure(), 401)]);

    expect(app(ImportPositionsFromIbkrAction::class)->handle())->toBe(0)
        ->and(Position::count())->toBe(0);
});

it('handles an empty portfolio', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    expect(app(ImportPositionsFromIbkrAction::class)->handle())->toBe(0);
});

it('defaults currency and asset class when the broker omits them', function (): void {
    Http::fake(['*' => Http::response([
        ['ticker' => 'BARE', 'conid' => 999, 'position' => 2, 'avgCost' => 5.0],
    ], 200)]);

    app(ImportPositionsFromIbkrAction::class)->handle();

    $position = Position::where('symbol', 'BARE')->sole();

    expect($position->currency)->toBe('USD')
        ->and($position->market)->toBe('STK');
});

it('tags imported positions with the live account when running in live mode', function (): void {
    config(['ibkr.mode' => 'live']);

    Http::fake(['*' => Http::response([
        ['ticker' => 'AAPL', 'conid' => 265598, 'position' => 1, 'avgCost' => 100.0],
    ], 200)]);

    app(ImportPositionsFromIbkrAction::class)->handle();

    $position = Position::where('symbol', 'AAPL')->sole();

    expect($position->account_mode)->toBe('live')
        ->and($position->broker_account_id)->toBe('U0000001');
});
