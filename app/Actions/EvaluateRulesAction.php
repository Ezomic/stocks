<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Order;
use App\Models\Position;
use App\Models\PriceSnapshot;
use App\Models\Rule;
use App\Services\IbkrAuthService;
use Illuminate\Support\Carbon;

class EvaluateRulesAction
{
    public function __construct(
        private readonly PlaceOrderAction $placeOrder,
        private readonly IbkrAuthService $auth,
    ) {}

    public function handle(): void
    {
        // Price sync stops writing snapshots when the session drops, but the scheduler keeps
        // calling this every minute. Evaluating on without a session means trading on whatever
        // price happened to be captured before the stall.
        if (! $this->auth->isAuthenticated()) {
            return;
        }

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

                if (! $snapshot || $snapshot->isStale()) {
                    return;
                }

                $rule = Rule::where('position_id', $position->id)->where('is_active', true)->first()
                    ?? $globalRule;

                if (! $rule || $rule->isInCooldown($position)) {
                    return;
                }

                $gainPct = $position->gainPct((float) $snapshot->price);

                $triggered = ($rule->take_profit_pct !== null && $gainPct >= (float) $rule->take_profit_pct)
                    || ($rule->stop_loss_pct !== null && $gainPct <= -(float) $rule->stop_loss_pct);

                if (! $triggered) {
                    return;
                }

                $this->placeOrder->handle($position, 'sell', $rule);
                $position->update(['last_triggered_at' => Carbon::now()]);
            });
    }
}
