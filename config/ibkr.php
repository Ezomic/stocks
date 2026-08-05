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
