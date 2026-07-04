<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Position;
use App\Services\IbkrClient;

class ImportPositionsFromIbkrAction
{
    public function __construct(private readonly IbkrClient $client) {}

    public function handle(): int
    {
        $response = $this->client->portfolioPositions();

        if (! $response->successful()) {
            return 0;
        }

        $imported = 0;
        $mode = config('ibkr.mode');
        $accountId = $this->client->accountId();

        foreach ($response->json() ?? [] as $item) {
            $symbol = $item['ticker'] ?? $item['symbol'] ?? null;
            $conid = (string) ($item['conid'] ?? '');

            if (! $symbol || ! $conid) {
                continue;
            }

            Position::updateOrCreate(
                ['symbol' => $symbol, 'broker_account_id' => $accountId],
                [
                    'account_mode' => $mode,
                    'quantity' => $item['position'] ?? 0,
                    'avg_buy_price' => $item['avgCost'] ?? 0,
                    'currency' => $item['currency'] ?? 'USD',
                    'market' => $item['assetClass'] ?? 'STK',
                    'ibkr_con_id' => $conid,
                ]
            );

            $imported++;
        }

        return $imported;
    }
}
