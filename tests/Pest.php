<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\IbkrFakeResponses;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * The first flashed validation message for a field. A redirect response leaves the error bag
 * in its serialised array form, so reading it back needs both shapes.
 */
function flashedError(string $field): string
{
    $errors = session('errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->first($field);
    }

    return (string) (data_get($errors, "default.messages.{$field}.0") ?? '');
}

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
