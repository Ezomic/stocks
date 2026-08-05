<?php

declare(strict_types=1);

use App\Models\PriceSnapshot;
use Illuminate\Console\Scheduling\Schedule;

function snapshotAgedDays(int $days): PriceSnapshot
{
    return PriceSnapshot::create([
        'symbol' => 'AAPL',
        'price' => '100.00',
        'currency' => 'USD',
        'source' => 'ibkr',
        'fetched_at' => now()->subDays($days),
    ]);
}

it('deletes snapshots older than the retention window', function (): void {
    config(['ibkr.snapshot_retention_days' => 30]);

    $old = snapshotAgedDays(45);
    $recent = snapshotAgedDays(2);

    $this->artisan('prices:prune')->assertSuccessful();

    expect(PriceSnapshot::whereKey($old->id)->exists())->toBeFalse()
        ->and(PriceSnapshot::whereKey($recent->id)->exists())->toBeTrue();
});

it('keeps a snapshot sitting exactly inside the window', function (): void {
    config(['ibkr.snapshot_retention_days' => 30]);

    $edge = snapshotAgedDays(29);

    $this->artisan('prices:prune')->assertSuccessful();

    expect(PriceSnapshot::whereKey($edge->id)->exists())->toBeTrue();
});

it('reports how many snapshots it pruned', function (): void {
    config(['ibkr.snapshot_retention_days' => 30]);

    snapshotAgedDays(45);
    snapshotAgedDays(60);
    snapshotAgedDays(1);

    $this->artisan('prices:prune')
        ->expectsOutputToContain('Pruned 2 price snapshots')
        ->assertSuccessful();
});

it('honours a retention override on the command line', function (): void {
    config(['ibkr.snapshot_retention_days' => 30]);

    snapshotAgedDays(10);

    $this->artisan('prices:prune', ['--days' => 5])->assertSuccessful();

    expect(PriceSnapshot::count())->toBe(0);
});

it('keeps everything when retention is disabled', function (): void {
    config(['ibkr.snapshot_retention_days' => 0]);

    snapshotAgedDays(400);

    $this->artisan('prices:prune')
        ->expectsOutputToContain('Retention is disabled')
        ->assertSuccessful();

    expect(PriceSnapshot::count())->toBe(1);
});

it('is scheduled to run daily', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'prices:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('15 2 * * *');
});
