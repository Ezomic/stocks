<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class ReplayRuleAction
{
    /**
     * Runs a proposed rule over the prices already on record and reports every point it would
     * have fired. Dry run answers the same question going forward, which means waiting a week
     * to learn whether a threshold is sensible.
     *
     * @return array{
     *     triggers: array<int, array{at: CarbonImmutable, price: float, threshold: string, peak: float|null}>,
     *     from: CarbonImmutable|null,
     *     to: CarbonImmutable|null,
     *     snapshots: int,
     * }
     */
    public function handle(Position $position, Rule $rule): array
    {
        /** @var Collection<int, PriceSnapshot> $snapshots */
        $snapshots = PriceSnapshot::where('symbol', $position->symbol)
            ->orderBy('fetched_at')
            ->get();

        $triggers = [];
        $peak = null;
        $lastTriggeredAt = null;

        foreach ($snapshots as $snapshot) {
            $price = (float) $snapshot->price;
            $at = CarbonImmutable::parse($snapshot->fetched_at);

            // The peak only knows what the replay has walked past, exactly as the live engine
            // would have known it at that moment.
            $peak = $peak === null ? $price : max($peak, $price);

            // The cooldown has to apply here too. Without it every tick past the threshold
            // counts as a trigger and the total is meaningless.
            if ($lastTriggeredAt !== null && $at->isBefore($lastTriggeredAt->addMinutes($rule->cooldown_minutes))) {
                continue;
            }

            $threshold = $this->crossedThreshold($position, $rule, $price, $peak);

            if ($threshold === null) {
                continue;
            }

            $triggers[] = [
                'at' => $at,
                'price' => $price,
                'threshold' => $threshold,
                'peak' => $rule->isTrailing() ? $peak : null,
            ];

            $lastTriggeredAt = $at;
        }

        return [
            'triggers' => $triggers,
            'from' => $snapshots->isEmpty() ? null : CarbonImmutable::parse($snapshots->first()->fetched_at),
            'to' => $snapshots->isEmpty() ? null : CarbonImmutable::parse($snapshots->last()->fetched_at),
            'snapshots' => $snapshots->count(),
        ];
    }

    private function crossedThreshold(Position $position, Rule $rule, float $price, float $peak): ?string
    {
        $gainPct = $position->gainPct($price);

        if ($rule->take_profit_pct !== null && $gainPct >= (float) $rule->take_profit_pct) {
            return 'take_profit';
        }

        $stopLossPrice = $rule->stopLossPrice($position, $rule->isTrailing() ? $peak : null);

        return $stopLossPrice !== null && $price <= $stopLossPrice ? 'stop_loss' : null;
    }
}
