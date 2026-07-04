<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Services\IbkrClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SyncPricesAction
{
    public function __construct(private readonly IbkrClient $client) {}

    public function handle(): void
    {
        $positions = Position::whereNotNull('ibkr_con_id')->get();

        if ($positions->isEmpty()) {
            return;
        }

        $positions->chunk(50)->each(function (Collection $chunk) {
            $conids = $chunk->pluck('ibkr_con_id')->map(fn ($id) => (int) $id)->all();

            $data = $this->fetchWithRetry($conids);

            $fetchedAt = Carbon::now();

            foreach ($chunk as $position) {
                $conid = (int) $position->ibkr_con_id;
                $row = collect($data)->firstWhere('conid', $conid);

                if (! $row || ! isset($row['31'])) {
                    continue;
                }

                PriceSnapshot::create([
                    'symbol' => $position->symbol,
                    'price' => (float) $row['31'],
                    'currency' => $position->currency,
                    'source' => 'ibkr',
                    'fetched_at' => $fetchedAt,
                ]);
            }
        });
    }

    private function fetchWithRetry(array $conids): array
    {
        $response = $this->client->snapshot($conids);
        $data = $response->json() ?? [];

        // IBKR returns empty or "not subscribed" on the first call — retry once
        $hasData = collect($data)->contains(fn ($row) => isset($row['31']));

        if (! $hasData) {
            sleep(1);
            $response = $this->client->snapshot($conids);
            $data = $response->json() ?? [];
        }

        return is_array($data) ? $data : [];
    }
}
