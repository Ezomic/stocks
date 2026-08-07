<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * These assert the query planner actually uses the new indexes. Without that check the
 * migration only proves the indexes exist, not that the queries they exist for benefit.
 */
function queryPlan(string $sql): string
{
    return collect(DB::select('explain query plan '.$sql))
        ->map(fn (object $row): string => (string) ($row->detail ?? ''))
        ->join(' | ');
}

it('uses an index to find orders still in flight', function (): void {
    expect(queryPlan("select position_id from orders where status in ('pending', 'placed')"))
        ->toContain('orders_status_index');
});

it('uses an index to find placed orders awaiting reconciliation', function (): void {
    expect(queryPlan("select * from orders where status = 'placed' and broker_order_id is not null"))
        ->toContain('orders_status_index');
});

it('uses an index to load the orders belonging to a position', function (): void {
    expect(queryPlan('select * from orders where position_id = 1'))
        ->toContain('orders_position_id_index');
});

it('uses an index to sweep expired price snapshots', function (): void {
    expect(queryPlan("select * from price_snapshots where fetched_at < '2026-01-01'"))
        ->toContain('price_snapshots_fetched_at_index');
});

it('still uses the composite index for the latest price of one symbol', function (): void {
    expect(queryPlan("select * from price_snapshots where symbol = 'AAPL' order by fetched_at desc limit 1"))
        ->toContain('price_snapshots_symbol_fetched_at_index');
});
