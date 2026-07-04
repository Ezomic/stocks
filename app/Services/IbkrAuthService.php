<?php

declare(strict_types=1);

namespace App\Services;

class IbkrAuthService
{
    public function __construct(private readonly IbkrClient $client) {}

    public function isAuthenticated(): bool
    {
        try {
            $response = $this->client->authStatus();

            return $response->successful() && ($response->json('authenticated') === true);
        } catch (\Throwable) {
            return false;
        }
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
        try {
            $this->client->reauthenticate();

            // Poll up to 10 seconds for the session to come back
            for ($i = 0; $i < 10; $i++) {
                sleep(1);
                if ($this->isAuthenticated()) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }
}
