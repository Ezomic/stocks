<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class IbkrAuthService
{
    private const CACHE_KEY = 'ibkr.authenticated';

    public function __construct(private readonly IbkrClient $client) {}

    /**
     * Asked on every dashboard render and every scheduled rule evaluation, so the answer is
     * held briefly rather than costing a gateway round trip each time.
     */
    public function isAuthenticated(): bool
    {
        return (bool) Cache::remember(
            self::CACHE_KEY,
            $this->cacheTtlSeconds(),
            fn (): bool => $this->fetchAuthenticated()
        );
    }

    public function tickle(): bool
    {
        try {
            return $this->client->tickle()->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function reauthenticate(): bool
    {
        Cache::forget(self::CACHE_KEY);

        try {
            $this->client->reauthenticate();

            // Poll up to 10 seconds for the session to come back
            for ($i = 0; $i < 10; $i++) {
                sleep(1);

                if ($this->fetchAuthenticated()) {
                    Cache::put(self::CACHE_KEY, true, $this->cacheTtlSeconds());

                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function fetchAuthenticated(): bool
    {
        try {
            $response = $this->client->authStatus();

            return $response->successful() && ($response->json('authenticated') === true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function cacheTtlSeconds(): int
    {
        $ttl = config('ibkr.auth_status_ttl_seconds');

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 10;
    }
}
