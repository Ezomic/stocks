<?php

declare(strict_types=1);

use App\Models\Position;
use App\Models\User;
use App\Services\IbkrAuthService;
use App\Services\IbkrClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('applies an explicit timeout and connect timeout to gateway calls', function (): void {
    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(['authenticated' => true], 200);
    });

    app(IbkrClient::class)->authStatus();

    expect($options['timeout'])->toBe(10)
        ->and($options['connect_timeout'])->toBe(3);
});

it('honours configured timeout values', function (): void {
    config(['ibkr.timeout_seconds' => 4, 'ibkr.connect_timeout_seconds' => 1]);

    $options = [];

    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(['authenticated' => true], 200);
    });

    app(IbkrClient::class)->authStatus();

    expect($options['timeout'])->toBe(4)
        ->and($options['connect_timeout'])->toBe(1);
});

it('asks the gateway for the session status only once inside the cache window', function (): void {
    fakeIbkrAuth();

    $auth = app(IbkrAuthService::class);

    expect($auth->isAuthenticated())->toBeTrue()
        ->and($auth->isAuthenticated())->toBeTrue()
        ->and($auth->isAuthenticated())->toBeTrue();

    Http::assertSentCount(1);
});

it('asks again once the cache window has passed', function (): void {
    fakeIbkrAuth();

    $auth = app(IbkrAuthService::class);
    $auth->isAuthenticated();

    $this->travel(30)->seconds();
    $auth->isAuthenticated();

    Http::assertSentCount(2);
});

it('does not hold on to a cached failure after a successful reauthentication', function (): void {
    Http::fake([
        '*/iserver/reauthenticate' => Http::response(['message' => 'triggered'], 200),
        '*/iserver/auth/status' => Http::sequence()
            ->push(['authenticated' => false], 200)
            ->whenEmpty(Http::response(['authenticated' => true], 200)),
    ]);

    $auth = app(IbkrAuthService::class);
    expect($auth->isAuthenticated())->toBeFalse();

    expect($auth->reauthenticate())->toBeTrue()
        ->and($auth->isAuthenticated())->toBeTrue();
});

it('loads the dashboard with one price query no matter how many positions exist', function (): void {
    fakeIbkrAuth();
    Position::factory()->count(6)->create();

    $this->actingAs(User::factory()->create());

    DB::enableQueryLog();
    $this->get('/')->assertOk();

    expect(priceSnapshotQueryCount())->toBe(1);
});

it('loads the positions index with one price query no matter how many positions exist', function (): void {
    Position::factory()->count(6)->create();

    $this->actingAs(User::factory()->create());

    DB::enableQueryLog();
    $this->get('/positions')->assertOk();

    expect(priceSnapshotQueryCount())->toBe(1);
});

function priceSnapshotQueryCount(): int
{
    return collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'price_snapshots'))
        ->count();
}
