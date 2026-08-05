<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\IbkrFakeResponses;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Rule evaluation refuses to run without a live gateway session. Register this before any
 * other fake: Http::fake() merges stubs and the first matching pattern wins.
 */
function fakeIbkrAuth(bool $authenticated = true): void
{
    Http::fake([
        '*/iserver/auth/status' => Http::response(IbkrFakeResponses::authenticated($authenticated), 200),
    ]);
}
