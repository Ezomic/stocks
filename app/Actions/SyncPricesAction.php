<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\WatchlistItem;
use App\Services\IbkrClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SyncPricesAction
{
    public function __construct(private readonly IbkrClient $client) {}

    public function handle(): void
    {
        // Watchlist entries are priced alongside held positions: a symbol you are deciding
        // whether to buy is exactly the one you want a price history for.
        $priced = Position::forActiveAccount()->whereNotNull('ibkr_con_id')->get()
            ->concat(WatchlistItem::all())
            ->unique(fn (Position|WatchlistItem $subject): string => (string) $subject->ibkr_con_id)
            ->values();

        if ($priced->isEmpty()) {
            return;
        }

        $priced->chunk(50)->each(function (Collection $chunk) {
            $conids = $chunk->pluck('ibkr_con_id')->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)->all();

            $data = $this->fetchWithRetry($conids);

            $fetchedAt = Carbon::now();

            foreach ($chunk as $subject) {
                $conid = (int) $subject->ibkr_con_id;
                $row = collect($data)->firstWhere('conid', $conid);

                if (! is_array($row) || ! isset($row['31'])) {
                    continue;
                }

                PriceSnapshot::create([
                    'symbol' => $subject->symbol,
                    'price' => is_numeric($row['31']) ? (float) $row['31'] : 0.0,
                    'currency' => $subject->currency,
                    'source' => 'ibkr',
                    'fetched_at' => $fetchedAt,
                ]);
            }
        });
    }

    /**
     * @param  int[]  $conids
     * @return mixed[]
     */
    private function fetchWithRetry(array $conids): array
    {
        $response = $this->client->snapshot($conids);
        $data = $response->json() ?? [];

        if (! is_array($data)) {
            return [];
        }

        // IBKR returns empty or "not subscribed" on the first call — retry once
        $hasData = false;
        foreach ($data as $row) {
            if (is_array($row) && isset($row['31'])) {
                $hasData = true;
                break;
            }
        }

        if (! $hasData) {
            sleep(1);
            $response = $this->client->snapshot($conids);
            $data = $response->json() ?? [];
        }

        return is_array($data) ? $data : [];
    }
}
