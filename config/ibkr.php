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
];
