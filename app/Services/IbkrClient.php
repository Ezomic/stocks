<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class IbkrClient
{
    private string $gatewayUrl;

    private string $accountId;

    public function __construct()
    {
        $mode = config('ibkr.mode');
        $mode = is_string($mode) ? $mode : '';
        $gatewayUrl = config("ibkr.{$mode}.gateway_url");
        $accountId = config("ibkr.{$mode}.account_id");
        $this->gatewayUrl = is_string($gatewayUrl) ? $gatewayUrl : '';
        $this->accountId = is_string($accountId) ? $accountId : '';
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function authStatus(): Response
    {
        return $this->http()->get('/v1/api/iserver/auth/status');
    }

    public function tickle(): Response
    {
        return $this->http()->post('/v1/api/tickle');
    }

    public function reauthenticate(): Response
    {
        return $this->http()->post('/v1/api/iserver/reauthenticate');
    }

    public function searchContracts(string $symbol, string $secType = 'STK'): Response
    {
        return $this->http()->post('/v1/api/iserver/secdef/search', [
            'symbol' => $symbol,
            'secType' => $secType,
        ]);
    }

    /**
     * Fetch price snapshots for one or more conids.
     * Field 31 = last traded price.
     */
    /** @param int[] $conids */
    public function snapshot(array $conids): Response
    {
        return $this->http()->get('/v1/api/iserver/marketdata/snapshot', [
            'conids' => implode(',', $conids),
            'fields' => '31',
        ]);
    }

    public function portfolioPositions(): Response
    {
        return $this->http()->get("/v1/api/portfolio/{$this->accountId}/positions/0");
    }

    /** @param array<string, mixed> $order */
    public function placeOrder(array $order): Response
    {
        return $this->http()->post("/v1/api/iserver/account/{$this->accountId}/orders", [
            'orders' => [$order],
        ]);
    }

    public function confirmOrder(string $replyId): Response
    {
        return $this->http()->post("/v1/api/iserver/reply/{$replyId}", ['confirmed' => true]);
    }

    public function getOrders(): Response
    {
        return $this->http()->get('/v1/api/iserver/account/orders');
    }

    public function cancelOrder(string $brokerOrderId): Response
    {
        return $this->http()->delete("/v1/api/iserver/account/{$this->accountId}/order/{$brokerOrderId}");
    }

    private function http(): PendingRequest
    {
        return Http::withOptions([
            'verify' => false,
            'base_uri' => $this->gatewayUrl,
        ])
            ->timeout($this->seconds('timeout_seconds', 10))
            ->connectTimeout($this->seconds('connect_timeout_seconds', 3))
            ->acceptJson()
            ->contentType('application/json');
    }

    private function seconds(string $key, int $default): int
    {
        $value = config("ibkr.{$key}");

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }
}
