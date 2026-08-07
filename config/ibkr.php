<?php

return [
    'paper' => [
        'gateway_url' => env('IBKR_PAPER_GATEWAY_URL', 'https://localhost:5001'),
        'account_id' => env('IBKR_PAPER_ACCOUNT_ID'),
    ],
    'live' => [
        'gateway_url' => env('IBKR_LIVE_GATEWAY_URL', 'https://localhost:5000'),
        'account_id' => env('IBKR_LIVE_ACCOUNT_ID'),
    ],
    'mode' => env('IBKR_MODE', 'paper'),

    /*
     * How old a price snapshot may be and still be traded on. Price sync stops silently when
     * the gateway session drops, so without this the engine would keep firing rules against
     * a price that no longer reflects the market.
     */
    'max_price_age_minutes' => (int) env('IBKR_MAX_PRICE_AGE_MINUTES', 5),

    /*
     * How long price snapshots are kept. One row per position per minute adds up to roughly
     * half a million rows per position per year in a single SQLite file, and nothing reads
     * further back than the position chart. Set to 0 to keep everything.
     */
    'snapshot_retention_days' => (int) env('STOCKS_SNAPSHOT_RETENTION_DAYS', 30),

    /*
     * How long a placed order may go unconfirmed before it is reconciled against the broker's
     * own position and taken out of flight. Rule evaluation skips a position while it has an
     * order in flight, so an order that never resolves would otherwise freeze it for good.
     */
    'order_reconcile_timeout_minutes' => (int) env('IBKR_ORDER_RECONCILE_TIMEOUT_MINUTES', 30),

    /*
     * The gateway runs on localhost, so anything slow is wedged rather than far away. Short
     * limits keep a stalled gateway from holding a page render or a scheduled run open.
     */
    'timeout_seconds' => (int) env('IBKR_TIMEOUT_SECONDS', 10),
    'connect_timeout_seconds' => (int) env('IBKR_CONNECT_TIMEOUT_SECONDS', 3),

    /*
     * Every dashboard render asks whether the session is alive. Caching that answer briefly
     * keeps the page off the gateway without hiding a dropped session for long.
     */
    'auth_status_ttl_seconds' => (int) env('IBKR_AUTH_STATUS_TTL_SECONDS', 10),
];
