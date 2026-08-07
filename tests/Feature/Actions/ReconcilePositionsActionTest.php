<?php

declare(strict_types=1);

use App\Actions\ReconcilePositionsAction;
use App\Models\Position;
use App\Models\User;
use App\Notifications\PositionsDrifted;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\IbkrFakeResponses;

beforeEach(function (): void {
    Http::preventStrayRequests();
    fakeIbkrAuth();
    Notification::fake();

    $this->user = User::factory()->create();
});

/** @param array<int, array<string, mixed>> $rows */
function fakeBrokerPortfolio(array $rows): void
{
    Http::fake(['*/portfolio/*/positions/*' => Http::response($rows, 200)]);
}

it('records the broker quantity without changing the local one', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10', 'ibkr_con_id' => '265598']);

    fakeBrokerPortfolio([['conid' => 265598, 'position' => 7.0]]);

    app(ReconcilePositionsAction::class)->handle();

    $position->refresh();

    expect((float) $position->quantity)->toBe(10.0)
        ->and((float) $position->broker_quantity)->toBe(7.0)
        ->and($position->reconciled_at)->not->toBeNull()
        ->and($position->hasDrift())->toBeTrue();
});

it('reports no drift when the two agree', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10', 'ibkr_con_id' => '265598']);

    fakeBrokerPortfolio([['conid' => 265598, 'position' => 10.0]]);

    $result = app(ReconcilePositionsAction::class)->handle();

    expect($position->refresh()->hasDrift())->toBeFalse()
        ->and($result['drifted'])->toBe(0)
        ->and($result['reconciled'])->toBe(1);

    Notification::assertNothingSent();
});

it('treats a contract the broker does not list as a holding of zero', function (): void {
    $position = Position::factory()->create(['symbol' => 'GONE', 'quantity' => '5', 'ibkr_con_id' => '999']);

    fakeBrokerPortfolio([['conid' => 265598, 'position' => 10.0]]);

    app(ReconcilePositionsAction::class)->handle();

    expect((float) $position->refresh()->broker_quantity)->toBe(0.0)
        ->and($position->hasDrift())->toBeTrue()
        ->and((float) $position->quantity)->toBe(5.0);
});

it('notifies about drifted positions', function (): void {
    Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10', 'ibkr_con_id' => '265598']);

    fakeBrokerPortfolio([['conid' => 265598, 'position' => 3.0]]);

    app(ReconcilePositionsAction::class)->handle();

    Notification::assertSentTo(
        $this->user,
        PositionsDrifted::class,
        fn (PositionsDrifted $notification): bool => $notification->symbols === ['AAPL']
    );
});

it('skips a position with no conid and says so', function (): void {
    Position::factory()->create(['symbol' => 'MANUAL', 'quantity' => '5', 'ibkr_con_id' => null]);

    fakeBrokerPortfolio([]);

    $result = app(ReconcilePositionsAction::class)->handle();

    expect($result['unknown'])->toBe(1)
        ->and($result['reconciled'])->toBe(0);
});

it('leaves positions from another account alone', function (): void {
    $other = Position::factory()->create([
        'symbol' => 'THEIRS',
        'quantity' => '5',
        'ibkr_con_id' => '265598',
        'account_mode' => 'live',
        'broker_account_id' => 'U0000001',
    ]);

    fakeBrokerPortfolio([['conid' => 265598, 'position' => 1.0]]);

    app(ReconcilePositionsAction::class)->handle();

    expect($other->refresh()->broker_quantity)->toBeNull();
});

it('changes nothing when the broker cannot be reached', function (): void {
    $position = Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10', 'ibkr_con_id' => '265598']);

    Http::fake(['*/portfolio/*/positions/*' => Http::response(IbkrFakeResponses::authFailure(), 401)]);

    $result = app(ReconcilePositionsAction::class)->handle();

    expect($position->refresh()->broker_quantity)->toBeNull()
        ->and($result['reconciled'])->toBe(0);
});

it('warns about drift on the dashboard', function (): void {
    Position::factory()->create([
        'symbol' => 'AAPL',
        'quantity' => '10',
        'broker_quantity' => '3',
        'ibkr_con_id' => '265598',
    ]);

    $this->actingAs($this->user)->get('/')
        ->assertOk()
        ->assertSee('match the broker', false)
        ->assertSee('IBKR 3')
        ->assertSee('drift');
});

it('runs the command and reports the counts', function (): void {
    Position::factory()->create(['symbol' => 'AAPL', 'quantity' => '10', 'ibkr_con_id' => '265598']);

    fakeBrokerPortfolio([['conid' => 265598, 'position' => 3.0]]);

    $this->artisan('ibkr:reconcile-positions')
        ->expectsOutputToContain('Reconciled 1 positions, 1 differ')
        ->assertSuccessful();
});

it('is scheduled daily', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'ibkr:reconcile-positions'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 2 * * *');
});
