<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Position;
use App\Models\User;
use App\Notifications\PositionsDrifted;
use App\Services\IbkrClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ReconcilePositionsAction
{
    public function __construct(private readonly IbkrClient $client) {}

    /**
     * Records what the broker says each position holds without changing what the app holds.
     * A quantity that moved underneath the app is a fact worth seeing, not something to paper
     * over: overwriting silently would hide the very problem this exists to catch.
     *
     * @return array{reconciled: int, drifted: int, unknown: int}
     */
    public function handle(): array
    {
        $brokerQuantities = $this->brokerQuantitiesByConid();

        if ($brokerQuantities === null) {
            return ['reconciled' => 0, 'drifted' => 0, 'unknown' => 0];
        }

        $positions = Position::forActiveAccount()->get();
        $now = Carbon::now();
        $drifted = new Collection;
        $unknown = 0;

        foreach ($positions as $position) {
            $conid = (string) $position->ibkr_con_id;

            if ($conid === '') {
                $unknown++;

                continue;
            }

            // A contract the broker does not list is a contract it holds none of.
            $brokerQuantity = (float) $brokerQuantities->get($conid, 0.0);

            $position->update([
                'broker_quantity' => $brokerQuantity,
                'reconciled_at' => $now,
            ]);

            if ($position->refresh()->hasDrift()) {
                $drifted->push($position);
            }
        }

        $this->report($drifted);

        return [
            'reconciled' => $positions->count() - $unknown,
            'drifted' => $drifted->count(),
            'unknown' => $unknown,
        ];
    }

    /**
     * @param  Collection<int, Position>  $drifted
     */
    private function report(Collection $drifted): void
    {
        if ($drifted->isEmpty()) {
            return;
        }

        foreach ($drifted as $position) {
            Log::warning('Position quantity differs from the broker', [
                'symbol' => $position->symbol,
                'local' => $position->quantity,
                'broker' => $position->broker_quantity,
            ]);
        }

        try {
            $symbols = $drifted->map(fn (Position $position): string => $position->symbol)->all();

            Notification::send(User::all(), new PositionsDrifted($symbols));
        } catch (\Throwable $e) {
            Log::warning('Drift notification could not be dispatched: '.$e->getMessage());
        }
    }

    /**
     * @return Collection<string, float>|null
     */
    private function brokerQuantitiesByConid(): ?Collection
    {
        $response = $this->client->portfolioPositions();

        if (! $response->successful()) {
            return null;
        }

        $rows = $response->json();

        if (! is_array($rows)) {
            return null;
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row) && isset($row['conid']))
            ->mapWithKeys(function (array $row): array {
                $conid = $row['conid'];
                $quantity = $row['position'] ?? 0;

                return [
                    (is_scalar($conid) ? (string) $conid : '') => is_numeric($quantity) ? (float) $quantity : 0.0,
                ];
            });
    }
}
