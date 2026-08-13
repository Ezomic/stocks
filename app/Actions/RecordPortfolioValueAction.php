<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\PortfolioValue;
use App\Models\Position;
use App\Models\PriceSnapshot;
use Illuminate\Support\Carbon;

class RecordPortfolioValueAction
{
    /**
     * Records what is held right now, per currency. Written as its own row rather than derived
     * later, so deleting a position afterwards cannot rewrite what the portfolio was worth on
     * a day it was still held.
     *
     * @return int the number of currencies recorded
     */
    public function handle(?Carbon $on = null): int
    {
        $on = ($on ?? Carbon::now())->startOfDay();

        $groups = Position::forActiveAccount()
            ->with('latestSnapshot')
            ->get()
            ->groupBy('currency');

        foreach ($groups as $currency => $positions) {
            $value = 0.0;
            $cost = 0.0;

            foreach ($positions as $position) {
                $snapshot = $position->latestSnapshot;
                $quantity = (float) $position->quantity;

                // A position with no price contributes nothing to value but still cost real
                // money, so counting its cost would show a permanent phantom loss.
                if (! $snapshot instanceof PriceSnapshot) {
                    continue;
                }

                $value += (float) $snapshot->price * $quantity;
                $cost += (float) $position->avg_buy_price * $quantity;
            }

            PortfolioValue::updateOrCreate(
                ['recorded_on' => $on, 'currency' => $currency],
                ['value' => $value, 'cost' => $cost, 'positions' => $positions->count()],
            );
        }

        return $groups->count();
    }
}
