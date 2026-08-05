<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use Illuminate\Support\Carbon;

class EvaluateRulesAction
{
    public function __construct(private readonly PlaceOrderAction $placeOrder) {}

    public function handle(): void
    {
        $globalRule = Rule::whereNull('position_id')->where('is_active', true)->first();

        // An order that is placed but not yet reconciled has not reduced the position quantity
        // yet, so evaluating against it would sell the same holding twice.
        $positionsWithOpenOrders = Order::whereIn('status', ['pending', 'placed'])
            ->pluck('position_id')
            ->all();

        Position::forActiveAccount()
            ->where('quantity', '>', 0)
            ->whereNotIn('id', $positionsWithOpenOrders)
            ->get()
            ->each(function (Position $position) use ($globalRule) {
                $snapshot = PriceSnapshot::latestFor($position->symbol);

                if (! $snapshot) {
                    return;
                }

                $rule = Rule::where('position_id', $position->id)->where('is_active', true)->first()
                    ?? $globalRule;

                if (! $rule) {
                    return;
                }

                $gainPct = $position->gainPct((float) $snapshot->price);

                if ($rule->take_profit_pct !== null && $gainPct >= (float) $rule->take_profit_pct) {
                    if (! $rule->isInCooldown()) {
                        $this->placeOrder->handle($position, 'sell', $rule);
                        $rule->update(['last_triggered_at' => Carbon::now()]);
                    }
                } elseif ($rule->stop_loss_pct !== null && $gainPct <= -(float) $rule->stop_loss_pct) {
                    if (! $rule->isInCooldown()) {
                        $this->placeOrder->handle($position, 'sell', $rule);
                        $rule->update(['last_triggered_at' => Carbon::now()]);
                    }
                }
            });
    }
}
